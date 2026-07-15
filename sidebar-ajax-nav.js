(function () {
  if (window.__sidebarAjaxNavInitialized) {
    return;
  }
  window.__sidebarAjaxNavInitialized = true;

  async function ajaxNavigate(url, pushState) {
    try {
      var response = await fetch(url, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
      });

      if (!response.ok) {
        window.location.href = url;
        return;
      }

      var contentType = response.headers.get('content-type') || '';
      if (contentType.indexOf('text/html') === -1) {
        window.location.href = url;
        return;
      }

      var html = await response.text();

      if (pushState) {
        window.history.pushState({ sidebarAjaxNav: true }, '', url);
      }

      // Full-document swap avoids script/text rendering glitches from partial replacement.
      document.open();
      document.write(html);
      document.close();
    } catch (error) {
      window.location.href = url;
    }
  }

  function shouldInterceptLink(anchor) {
    if (!anchor) return false;
    if (anchor.target && anchor.target.toLowerCase() === '_blank') return false;
    if (anchor.hasAttribute('download')) return false;

    var href = anchor.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return false;

    var url = new URL(href, window.location.href);
    if (url.origin !== window.location.origin) return false;

    var isPhpPage = /\.php($|\?)/i.test(url.pathname + url.search);
    if (!isPhpPage) return false;

    return true;
  }

  document.addEventListener('click', function (event) {
    var anchor = event.target.closest('aside a[href]');
    if (!shouldInterceptLink(anchor)) return;

    var targetUrl = new URL(anchor.getAttribute('href'), window.location.href).toString();
    var currentUrl = window.location.href;

    if (targetUrl === currentUrl) {
      event.preventDefault();
      return;
    }

    event.preventDefault();
    ajaxNavigate(targetUrl, true);
  });

  window.addEventListener('popstate', function () {
    ajaxNavigate(window.location.href, false);
  });
})();
