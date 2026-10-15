/**
 * @file
 * Shrinks photos in the browser before a ground initiative form uploads them.
 *
 * Phone photos are often 5-15 MB, which can exceed the server's upload limit
 * and lose the whole form. Each chosen photo over 1 MB or 1600 px is redrawn
 * at most 1600 px on the long side (the size the server keeps anyway) as a
 * JPEG, which also leaves out its location data; the quality is lowered in
 * steps until it fits the per-file limit. Without JavaScript the originals
 * are sent as before.
 *
 * The file input carries data-bfep-max-file and data-bfep-max-post (bytes);
 * if the photos are still too large after shrinking, the choice is cleared
 * and a message says which photo is the problem.
 */
((Drupal, once) => {
  const MAX_EDGE = 1600;
  const QUALITIES = [0.9, 0.8, 0.7, 0.6];

  const formatSize = (bytes) => `${(bytes / 1048576).toFixed(1)} MB`;

  async function shrink(file, maxFile) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || typeof createImageBitmap !== 'function') {
      return file;
    }
    let bitmap;
    try {
      bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    }
    catch (error) {
      // Not decodable here; let the server say what is wrong with it.
      return file;
    }
    const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
    if (scale === 1 && file.size < 1048576) {
      bitmap.close();
      return file;
    }
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    const context = canvas.getContext('2d');
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();
    let blob = null;
    for (const quality of QUALITIES) {
      // eslint-disable-next-line no-await-in-loop
      blob = await new Promise((resolve) => { canvas.toBlob(resolve, 'image/jpeg', quality); });
      if (!blob || blob.size <= maxFile) {
        break;
      }
    }
    if (!blob || blob.size >= file.size) {
      return file;
    }
    const name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
    return new File([blob], name, { type: 'image/jpeg', lastModified: file.lastModified });
  }

  Drupal.behaviors.bfepInitiativePhotos = {
    attach(context) {
      once('bfep-initiative-photos', 'input[type=file][data-bfep-max-file]', context).forEach((input) => {
        const maxFile = Number(input.dataset.bfepMaxFile) || Infinity;
        const maxPost = Number(input.dataset.bfepMaxPost) || Infinity;
        const status = document.createElement('div');
        status.className = 'bfep-photo-status';
        status.setAttribute('role', 'status');
        input.insertAdjacentElement('afterend', status);
        const form = input.form;
        let working = null;

        input.addEventListener('change', () => {
          const files = Array.from(input.files || []);
          if (!files.length || typeof DataTransfer !== 'function') {
            status.textContent = '';
            return;
          }
          status.textContent = Drupal.formatPlural(files.length, 'Preparing 1 photo…', 'Preparing @count photos…');
          working = Promise.all(files.map((file) => shrink(file, maxFile))).then((shrunk) => {
            const tooBig = shrunk.filter((file) => file.size > maxFile);
            const total = shrunk.reduce((sum, file) => sum + file.size, 0);
            if (tooBig.length || total > maxPost * 0.9) {
              input.value = '';
              status.textContent = tooBig.length
                ? Drupal.t('@name is too large (@size, the limit is @limit). Choose a smaller photo.', { '@name': tooBig[0].name, '@size': formatSize(tooBig[0].size), '@limit': formatSize(maxFile) })
                : Drupal.t('These photos add up to @size, more than one save can take. Add fewer at a time.', { '@size': formatSize(total) });
              return;
            }
            const transfer = new DataTransfer();
            shrunk.forEach((file) => transfer.items.add(file));
            input.files = transfer.files;
            status.textContent = Drupal.formatPlural(shrunk.length, '1 photo ready to upload (@size).', '@count photos ready to upload (@size).', { '@size': formatSize(total) });
          }).finally(() => { working = null; });
        });

        // Wait for shrinking to finish if the form is saved straight away.
        if (form) {
          form.addEventListener('submit', (event) => {
            if (working && !form.dataset.bfepPhotosReady) {
              event.preventDefault();
              const submitter = event.submitter;
              working.then(() => {
                form.dataset.bfepPhotosReady = '1';
                if (submitter) {
                  form.requestSubmit(submitter);
                }
                else {
                  form.requestSubmit();
                }
              });
            }
          });
        }
      });
    },
  };
})(Drupal, once);
