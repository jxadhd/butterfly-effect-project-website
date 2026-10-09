/**
 * @file
 * Campaign page share buttons: native share sheet and copy link.
 *
 * Both buttons start hidden and are only shown when the browser supports
 * them. The plain share links work without JavaScript.
 */
((Drupal, once) => {
  Drupal.behaviors.bfepShare = {
    attach(context) {
      once('bfep-share', '[data-bfep-share]', context).forEach((root) => {
        const { shareUrl: url, shareTitle: title } = root.dataset;
        const status = root.querySelector('[data-bfep-share-status]');
        const say = (message) => {
          if (status) {
            status.textContent = message;
          }
        };

        const nativeButton = root.querySelector('[data-bfep-share-native]');
        if (nativeButton && typeof navigator.share === 'function') {
          nativeButton.hidden = false;
          nativeButton.addEventListener('click', () => {
            navigator.share({ title, url }).catch(() => {});
          });
        }

        const copyButton = root.querySelector('[data-bfep-share-copy]');
        if (copyButton && navigator.clipboard && window.isSecureContext) {
          copyButton.hidden = false;
          copyButton.addEventListener('click', () => {
            navigator.clipboard.writeText(url).then(
              () => say(Drupal.t('Link copied.')),
              () => say(Drupal.t('Could not copy the link. Copy it from the address bar instead.')),
            );
          });
        }
      });
    },
  };
})(Drupal, once);
