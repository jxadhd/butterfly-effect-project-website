/**
 * @file
 * Infinite scroll for the public campaign lists (/campaigns, country pages).
 *
 * The server renders ordinary numbered pages (?p=2, ?p=3) with a Previous /
 * Next pager, and that is what crawlers and visitors without JavaScript get.
 * This script hides the pager and instead fetches the next numbered page,
 * takes its campaign cards and appends them to the grid:
 *
 * - automatically as the visitor nears the bottom, for the first few pages,
 *   then only from the "Load more campaigns" button, so the footer can still
 *   be reached;
 * - the button is always there as well, for keyboards, screen readers and
 *   browsers without IntersectionObserver;
 * - the address bar follows the page being read (history.replaceState), so a
 *   refresh, a shared link or the Back button returns to that page and the
 *   card the visitor was looking at;
 * - landing on ?p=3 offers "Show earlier campaigns" above the grid;
 * - a polite live region says how many campaigns were added.
 *
 * Filters and sort stay in the query string because every URL comes from the
 * server's own pager links.
 */
((Drupal, once) => {
  // Automatic loads per page view before the button takes over.
  const AUTO_LOADS = 4;
  // Start loading this far before the end of the list comes into view.
  const AUTO_MARGIN = '0px 0px 900px 0px';

  const cardSelector = '.bfep-campaign-card';
  const keyOf = (card) => card.querySelector('.bfep-campaign-title')?.getAttribute('href') || '';

  const pageOf = (url) => {
    const value = new URL(url, window.location.href).searchParams.get('p');
    return value && /^[1-9][0-9]*$/.test(value) ? Number(value) : 1;
  };

  const pageUrl = (page) => {
    const url = new URL(window.location.href);
    if (page > 1) {
      url.searchParams.set('p', String(page));
    }
    else {
      url.searchParams.delete('p');
    }
    url.hash = '';
    return url.toString();
  };

  /**
   * Fetches one numbered page and returns its cards and pager links.
   */
  const fetchPage = async (url) => {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'text/html' },
    });
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
    const root = doc.querySelector('[data-bfep-infinite]');
    const grid = doc.querySelector('[data-bfep-infinite-items]');
    if (!root || !grid) {
      // A redirect to a single campaign or an unexpected page.
      throw new Error('No campaign list in the response');
    }
    const link = (rel) => {
      const href = doc.querySelector(`[data-bfep-infinite-pager] a[rel="${rel}"]`)?.getAttribute('href');
      return href ? new URL(href, response.url).toString() : null;
    };
    return {
      cards: [...grid.querySelectorAll(cardSelector)],
      total: Number(root.dataset.bfepTotal) || 0,
      next: link('next'),
      prev: link('prev'),
    };
  };

  const button = (label) => {
    const element = document.createElement('button');
    element.type = 'button';
    element.className = 'button';
    element.textContent = label;
    return element;
  };

  const init = (root) => {
    const grid = root.querySelector('[data-bfep-infinite-items]');
    const pager = root.querySelector('[data-bfep-infinite-pager]');
    const summary = root.querySelector('[data-bfep-infinite-summary]');
    if (!grid || !pager || !window.fetch || !window.DOMParser || !window.history.replaceState) {
      return;
    }

    let total = Number(root.dataset.bfepTotal) || 0;
    const startPage = Number(root.dataset.bfepPage) || 1;
    let firstPage = startPage;
    let lastPage = startPage;
    let nextUrl = pager.querySelector('a[rel="next"]')?.href || null;
    let prevUrl = pager.querySelector('a[rel="prev"]')?.href || null;
    let autoLoads = 0;
    let busy = false;
    let failed = false;
    const seen = new Set([...grid.querySelectorAll(cardSelector)].map(keyOf));

    // One polite live region for both directions. The results summary keeps
    // its text up to date but stops announcing, so nothing is read twice.
    const live = document.createElement('p');
    live.className = 'visually-hidden';
    live.setAttribute('aria-live', 'polite');
    root.append(live);
    const announce = (message) => {
      live.textContent = '';
      window.setTimeout(() => { live.textContent = message; }, 50);
    };
    if (summary) {
      summary.removeAttribute('role');
    }

    // "Load more" below the grid; "Show earlier" above it on ?p=2 and later.
    const after = document.createElement('div');
    after.className = 'bfep-load-more';
    const moreButton = button(Drupal.t('Load more campaigns'));
    const afterMessage = document.createElement('p');
    afterMessage.className = 'bfep-load-more__message';
    afterMessage.hidden = true;
    after.append(afterMessage, moreButton);
    pager.after(after);

    const before = document.createElement('div');
    before.className = 'bfep-load-more bfep-load-more--earlier';
    const earlierButton = button(Drupal.t('Show earlier campaigns'));
    before.append(earlierButton);
    before.hidden = !prevUrl;
    grid.before(before);

    pager.hidden = true;
    root.classList.add('bfep-infinite-ready');

    const updateSummary = () => {
      if (!summary) {
        return;
      }
      const shown = grid.querySelectorAll(cardSelector).length;
      summary.textContent = Drupal.formatPlural(
        total,
        'Showing @shown of 1 campaign',
        'Showing @shown of @count campaigns',
        { '@shown': shown },
      );
    };

    const finish = () => {
      moreButton.hidden = true;
      afterMessage.hidden = false;
      afterMessage.textContent = firstPage === 1
        ? Drupal.formatPlural(total, 'All 1 campaign is shown.', 'All @count campaigns are shown.')
        : Drupal.t('You have reached the end of the list.');
    };

    // Adds fetched cards, skipping any already on the page (the order can
    // shift between requests when a campaign is updated), and returns them.
    const insert = (cards, page, where) => {
      const added = [];
      const fragment = document.createDocumentFragment();
      cards.forEach((card) => {
        const key = keyOf(card);
        if (key && seen.has(key)) {
          return;
        }
        seen.add(key);
        const node = document.importNode(card, true);
        node.dataset.bfepPage = String(page);
        fragment.append(node);
        added.push(node);
      });
      if (where === 'start') {
        grid.prepend(fragment);
      }
      else {
        grid.append(fragment);
      }
      added.forEach((node) => Drupal.attachBehaviors(node, window.drupalSettings));
      return added;
    };

    const setBusy = (target, label, isBusy) => {
      busy = isBusy;
      root.setAttribute('aria-busy', isBusy ? 'true' : 'false');
      // aria-disabled rather than disabled, so a focused button keeps focus.
      target.setAttribute('aria-disabled', isBusy ? 'true' : 'false');
      target.textContent = label;
    };

    const showError = () => {
      failed = true;
      afterMessage.hidden = false;
      afterMessage.textContent = Drupal.t('Could not load more campaigns. Check your connection and try again.');
      announce(afterMessage.textContent);
    };

    let observer = null;

    const loadNext = async (byButton) => {
      if (busy || !nextUrl) {
        return;
      }
      setBusy(moreButton, Drupal.t('Loading more campaigns…'), true);
      afterMessage.hidden = true;
      try {
        const page = pageOf(nextUrl);
        const result = await fetchPage(nextUrl);
        failed = false;
        total = result.total || total;
        const added = insert(result.cards, page, 'end');
        lastPage = page;
        nextUrl = result.next;
        updateSummary();
        announce(Drupal.formatPlural(added.length, '1 more campaign loaded.', '@count more campaigns loaded.'));
        if (byButton && added[0]) {
          added[0].querySelector('a')?.focus();
        }
      }
      catch (error) {
        showError();
      }
      setBusy(moreButton, Drupal.t('Load more campaigns'), false);
      if (!nextUrl) {
        finish();
        observer?.disconnect();
      }
      else if (observer && autoLoads < AUTO_LOADS && !failed) {
        // Re-observe so a short page that leaves the end still in view
        // triggers the next load straight away.
        observer.unobserve(after);
        observer.observe(after);
      }
    };

    const loadPrevious = async () => {
      if (busy || !prevUrl) {
        return;
      }
      setBusy(earlierButton, Drupal.t('Loading earlier campaigns…'), true);
      try {
        const page = pageOf(prevUrl);
        const result = await fetchPage(prevUrl);
        total = result.total || total;
        const added = insert(result.cards, page, 'start');
        firstPage = page;
        prevUrl = result.prev;
        updateSummary();
        announce(Drupal.formatPlural(added.length, '1 earlier campaign loaded.', '@count earlier campaigns loaded.'));
        // The button sits above the grid, so the new cards appear right
        // under it; take keyboard focus to the first of them.
        added[0]?.querySelector('a')?.focus();
      }
      catch (error) {
        announce(Drupal.t('Could not load earlier campaigns. Check your connection and try again.'));
      }
      setBusy(earlierButton, Drupal.t('Show earlier campaigns'), false);
      before.hidden = !prevUrl;
      if (!nextUrl && firstPage === 1) {
        finish();
      }
    };

    moreButton.addEventListener('click', () => loadNext(true));
    earlierButton.addEventListener('click', loadPrevious);

    if ('IntersectionObserver' in window) {
      observer = new IntersectionObserver((entries) => {
        if (!entries.some((entry) => entry.isIntersecting) || busy || failed || !nextUrl) {
          return;
        }
        if (autoLoads >= AUTO_LOADS) {
          observer.disconnect();
          return;
        }
        autoLoads += 1;
        loadNext(false);
      }, { rootMargin: AUTO_MARGIN });
      observer.observe(after);
    }

    // Keep the address bar on the page being read, and remember which card
    // was at the top so Back and refresh can return to it.
    const topCard = () => {
      const cards = grid.querySelectorAll(cardSelector);
      for (const card of cards) {
        if (card.getBoundingClientRect().bottom > 80) {
          return card;
        }
      }
      return cards[cards.length - 1] || null;
    };
    let currentPage = startPage;
    let saveTimer = 0;
    const save = () => {
      const card = topCard();
      if (!card) {
        return;
      }
      const page = Number(card.dataset.bfepPage) || startPage;
      const state = {
        ...(window.history.state || {}),
        bfepInfinite: { key: keyOf(card), offset: Math.round(card.getBoundingClientRect().top) },
      };
      try {
        window.history.replaceState(state, '', page === currentPage ? window.location.href : pageUrl(page));
        currentPage = page;
      }
      catch (error) {
        // Browsers cap replaceState calls; losing one only costs the
        // remembered position.
      }
    };
    window.addEventListener('scroll', () => {
      window.clearTimeout(saveTimer);
      saveTimer = window.setTimeout(save, 250);
    }, { passive: true });
    window.addEventListener('pagehide', save);

    // Coming back with Back or a refresh: the browser's own scroll position
    // is for a page of a different height, so return to the remembered card.
    const remembered = window.history.state?.bfepInfinite;
    const navigation = performance.getEntriesByType?.('navigation')?.[0]?.type;
    if (remembered?.key && (navigation === 'back_forward' || navigation === 'reload')) {
      const card = [...grid.querySelectorAll(cardSelector)].find((item) => keyOf(item) === remembered.key);
      if (card) {
        if ('scrollRestoration' in window.history) {
          window.history.scrollRestoration = 'manual';
        }
        const restore = () => window.scrollTo(0, window.scrollY + card.getBoundingClientRect().top - (remembered.offset || 0));
        restore();
        // Images and fonts can still move things until the load event.
        if (document.readyState !== 'complete') {
          window.addEventListener('load', restore, { once: true });
        }
      }
    }

    if (!nextUrl) {
      finish();
    }
  };

  Drupal.behaviors.bfepInfiniteScroll = {
    attach(context) {
      once('bfep-infinite', '[data-bfep-infinite]', context).forEach(init);
    },
  };
})(Drupal, once);
