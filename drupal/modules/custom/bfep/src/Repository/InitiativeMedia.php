<?php

declare(strict_types=1);

namespace Drupal\bfep\Repository;

use Drupal\Core\Database\Connection;

/**
 * Images and fundraising links of ground initiatives, stored in bfdb.
 *
 * Tables ground_initiative_images and ground_initiative_links are created by
 * sql/ground-initiative-media.sql (run once as the schema owner). Until then
 * available() is FALSE and callers hide images and links.
 *
 * Image rows never change their bytes: replacing a photo adds a new row, so
 * image URLs can be cached forever.
 */
final class InitiativeMedia {

  /**
   * Longest image edge after resizing, in pixels.
   */
  public const MAX_EDGE = 1600;

  /**
   * Largest accepted upload, in bytes.
   */
  public const MAX_UPLOAD = 20 * 1024 * 1024;

  private ?bool $available = NULL;

  public function __construct(
    private readonly \Closure $connection,
  ) {}

  /**
   * The bfdb connection, opened on first use.
   */
  private function db(): Connection {
    return ($this->connection)();
  }

  /**
   * Whether bfdb has the image and link tables.
   */
  public function available(): bool {
    if ($this->available === NULL) {
      try {
        $this->available = (int) $this->db()->query("
          SELECT CASE WHEN to_regclass('ground_initiative_images') IS NOT NULL
            AND to_regclass('ground_initiative_links') IS NOT NULL THEN 1 ELSE 0 END
        ")->fetchField() === 1;
      }
      catch (\Throwable) {
        $this->available = FALSE;
      }
    }
    return $this->available;
  }

  /**
   * Image details (not the bytes) for several initiatives, in display order.
   *
   * @param int[] $initiativeIds
   *   Initiative IDs.
   *
   * @return array<int, array<int, array{id: int, alt: string, ext: string, width: int, height: int}>>
   *   Images keyed by initiative ID, then image ID.
   */
  public function imagesFor(array $initiativeIds): array {
    if (!$initiativeIds || !$this->available()) {
      return [];
    }
    $rows = $this->db()->query('
      SELECT id, initiative_id, alt, mime_type, width, height
      FROM ground_initiative_images
      WHERE initiative_id IN (:ids[])
      ORDER BY initiative_id, sort_order, id
    ', [':ids[]' => array_map('intval', $initiativeIds)])->fetchAll();
    $images = [];
    foreach ($rows as $row) {
      $images[(int) $row->initiative_id][(int) $row->id] = [
        'id' => (int) $row->id,
        'alt' => (string) $row->alt,
        'ext' => self::extension((string) $row->mime_type),
        'width' => (int) $row->width,
        'height' => (int) $row->height,
      ];
    }
    return $images;
  }

  /**
   * Fundraising links of several initiatives, in display order.
   *
   * @param int[] $initiativeIds
   *   Initiative IDs.
   *
   * @return array<int, array<int, array{label: string, url: string}>>
   *   Links keyed by initiative ID.
   */
  public function linksFor(array $initiativeIds): array {
    if (!$initiativeIds || !$this->available()) {
      return [];
    }
    $links = [];
    $rows = $this->db()->query('
      SELECT initiative_id, label, url
      FROM ground_initiative_links
      WHERE initiative_id IN (:ids[])
      ORDER BY initiative_id, sort_order, id
    ', [':ids[]' => array_map('intval', $initiativeIds)])->fetchAll();
    foreach ($rows as $row) {
      $links[(int) $row->initiative_id][] = ['label' => (string) $row->label, 'url' => (string) $row->url];
    }
    return $links;
  }

  /**
   * One image's bytes and type, with whether its initiative is featured.
   *
   * @return array{mime: string, data: string, featured: bool}|null
   *   The image, or NULL when there is none with that ID.
   */
  public function imageData(int $imageId): ?array {
    if (!$this->available()) {
      return NULL;
    }
    $row = $this->db()->query("
      SELECT i.mime_type, encode(i.data, 'base64') AS data, g.featured_by_bfep
      FROM ground_initiative_images i
      INNER JOIN ground_initiatives g ON g.id = i.initiative_id
      WHERE i.id = :id
    ", [':id' => $imageId])->fetchObject();
    if (!$row) {
      return NULL;
    }
    return [
      'mime' => (string) $row->mime_type,
      'data' => (string) base64_decode((string) $row->data),
      'featured' => in_array($row->featured_by_bfep, [TRUE, 1, '1', 't', 'true'], TRUE),
    ];
  }

  /**
   * Updates alt text and order, removes images, and adds new ones.
   *
   * @param int $initiativeId
   *   The initiative.
   * @param array<int, array{alt: string, remove: bool, order: int}> $existing
   *   Changes to current images, keyed by image ID.
   * @param array<int, array{mime: string, data: string, width: int, height: int}> $new
   *   Prepared images (see prepareImage()) to add after the current ones.
   * @param string $defaultAlt
   *   Alt text for images saved without their own.
   */
  public function saveImages(int $initiativeId, array $existing, array $new, string $defaultAlt): void {
    $db = $this->db();
    $order = 0;
    uasort($existing, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
    foreach ($existing as $imageId => $change) {
      if ($change['remove']) {
        $db->delete('ground_initiative_images')->condition('id', $imageId)->condition('initiative_id', $initiativeId)->execute();
        continue;
      }
      $alt = trim($change['alt']);
      $db->update('ground_initiative_images')
        ->fields(['alt' => mb_substr($alt !== '' ? $alt : $defaultAlt, 0, 500), 'sort_order' => $order++])
        ->condition('id', $imageId)
        ->condition('initiative_id', $initiativeId)
        ->execute();
    }
    foreach ($new as $image) {
      // Bytes travel as base64 text, so no driver blob handling is needed.
      $db->query("
        INSERT INTO ground_initiative_images (initiative_id, mime_type, data, width, height, alt, sort_order)
        VALUES (:initiative_id, :mime, decode(:data, 'base64'), :width, :height, :alt, :sort_order)
      ", [
        ':initiative_id' => $initiativeId,
        ':mime' => $image['mime'],
        ':data' => base64_encode($image['data']),
        ':width' => $image['width'],
        ':height' => $image['height'],
        ':alt' => mb_substr($defaultAlt, 0, 500),
        ':sort_order' => $order++,
      ]);
    }
  }

  /**
   * Replaces an initiative's fundraising links.
   *
   * @param int $initiativeId
   *   The initiative.
   * @param array<int, array{label: string, url: string}> $links
   *   Links in display order.
   */
  public function saveLinks(int $initiativeId, array $links): void {
    $db = $this->db();
    $db->delete('ground_initiative_links')->condition('initiative_id', $initiativeId)->execute();
    foreach (array_values($links) as $order => $link) {
      $db->insert('ground_initiative_links')->fields([
        'initiative_id' => $initiativeId,
        'label' => mb_substr($link['label'], 0, 255),
        'url' => $link['url'],
        'sort_order' => $order,
      ])->execute();
    }
  }

  /**
   * Checks, shrinks and re-encodes an uploaded photo.
   *
   * Re-encoding also drops EXIF data such as the camera's GPS position.
   *
   * @param string $path
   *   The uploaded file.
   *
   * @return array{mime: string, data: string, width: int, height: int}|string
   *   The prepared image, or an error message when it cannot be used.
   */
  public static function prepareImage(string $path): array|string {
    $size = @filesize($path);
    if ($size === FALSE || $size === 0) {
      return 'The file is empty.';
    }
    if ($size > self::MAX_UPLOAD) {
      return 'The file is larger than 20 MB.';
    }
    $info = @getimagesize($path);
    $loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
    if ($info === FALSE || !isset($loaders[$info[2]]) || !function_exists($loaders[$info[2]])) {
      return 'Only JPG, PNG and WebP photos can be uploaded.';
    }
    if ($info[0] * $info[1] > 40_000_000) {
      return 'The photo has too many pixels.';
    }
    $source = @$loaders[$info[2]]($path);
    if ($source === FALSE) {
      return 'The photo could not be read.';
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
      $source = self::orient($source, (int) (@exif_read_data($path)['Orientation'] ?? 1));
    }
    [$width, $height] = self::fit(imagesx($source), imagesy($source), self::MAX_EDGE);
    $image = imagecreatetruecolor($width, $height);
    // White behind transparent PNG areas, since the result has no alpha.
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
    ob_start();
    $mime = function_exists('imagewebp') && imagewebp($image, NULL, 80) ? 'image/webp' : NULL;
    if ($mime === NULL) {
      ob_clean();
      imagejpeg($image, NULL, 82);
      $mime = 'image/jpeg';
    }
    $data = (string) ob_get_clean();
    return ['mime' => $mime, 'data' => $data, 'width' => $width, 'height' => $height];
  }

  /**
   * The size that fits within $max on the long edge, never enlarging.
   *
   * @return int[]
   *   Width and height.
   */
  public static function fit(int $width, int $height, int $max): array {
    $scale = min(1, $max / max($width, $height, 1));
    return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
  }

  /**
   * The URL file extension for a stored image type.
   */
  public static function extension(string $mime): string {
    return match ($mime) {
      'image/webp' => 'webp',
      'image/png' => 'png',
      default => 'jpg',
    };
  }

  /**
   * Turns a JPEG upright according to its EXIF orientation.
   */
  private static function orient(\GdImage $image, int $orientation): \GdImage {
    $rotated = match ($orientation) {
      3 => imagerotate($image, 180, 0),
      6 => imagerotate($image, -90, 0),
      8 => imagerotate($image, 90, 0),
      default => FALSE,
    };
    return $rotated ?: $image;
  }

}
