/**
 * @file
 * Slides the language switch's thumb before following the link.
 *
 * The options are ordinary links, so the switch works without this script;
 * it only adds the animation.
 */
((Drupal, once) => {
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  Drupal.behaviors.bfepLanguageSwitch = {
    attach(context) {
      once('bfep-lang', '[data-bfep-lang]', context).forEach((nav) => {
        const track = nav.querySelector('.bfep-lang__track');
        nav.querySelectorAll('.bfep-lang__option').forEach((option) => {
          option.addEventListener('click', (event) => {
            if (option.classList.contains('is-active')) {
              event.preventDefault();
              return;
            }
            if (reduceMotion.matches || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
              return;
            }
            event.preventDefault();
            nav.querySelectorAll('.bfep-lang__option').forEach((other) => {
              other.classList.toggle('is-active', other === option);
            });
            track.style.setProperty('--bfep-lang-index', option.dataset.index);
            nav.classList.add('is-switching');
            window.setTimeout(() => {
              window.location.assign(option.href);
            }, 260);
          });
        });
      });
    },
  };
})(Drupal, once);
