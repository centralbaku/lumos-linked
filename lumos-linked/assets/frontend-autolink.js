(function () {
  if (typeof window === "undefined" || typeof document === "undefined") {
    return;
  }

  var data = window.LumosLinkedData || {};
  var mappings = Array.isArray(data.mappings) ? data.mappings : [];
  var hoverStyle = String(data.hover_style || "underline");
  if (!mappings.length) {
    return;
  }

  function escapeRegex(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }

  var compiled = mappings
    .filter(function (m) {
      return m && m.id && m.keyword && m.target_url;
    })
    .sort(function (a, b) {
      return String(b.keyword).length - String(a.keyword).length;
    })
    .map(function (m) {
      var flags = "g" + (m.case_sensitive ? "" : "i");
      var pattern =
        "(^|[^A-Za-z0-9_])(" +
        escapeRegex(String(m.keyword)) +
        ")(?=[^A-Za-z0-9_]|$)";
      return {
        id: String(m.id),
        keyword: String(m.keyword),
        target: String(m.target_url),
        excludeFrom: Array.isArray(m.exclude_from) ? m.exclude_from : [],
        excludeTargetUrlPage: !!m.exclude_target_url_page,
        regex: new RegExp(pattern, flags),
      };
    });

  function normalizeUrlForCompare(url) {
    try {
      var parsed = new URL(String(url), window.location.origin);
      var path = (parsed.pathname || "/").replace(/\/+$/, "") || "/";
      return parsed.host.toLowerCase() + path.toLowerCase();
    } catch (e) {
      return String(url || "").trim().toLowerCase().replace(/\/+$/, "");
    }
  }

  function isTargetUrlPage(entry) {
    if (!entry.excludeTargetUrlPage) return false;
    var current = normalizeUrlForCompare(window.location.href || "");
    var target = normalizeUrlForCompare(entry.target || "");
    return current && target && current === target;
  }

  function isExcluded(entry) {
    if (isTargetUrlPage(entry)) return true;
    if (!entry.excludeFrom || !entry.excludeFrom.length) return false;
    var current = window.location.href || "";
    for (var i = 0; i < entry.excludeFrom.length; i++) {
      var needle = String(entry.excludeFrom[i] || "").trim();
      if (!needle) continue;
      if (current.toLowerCase().indexOf(needle.toLowerCase()) !== -1) return true;
    }
    return false;
  }

  function shouldSkipNode(node) {
    if (!node || !node.parentElement) return true;
    if (!node.nodeValue || !node.nodeValue.trim()) return true;
    return !!node.parentElement.closest(
      "a,script,style,textarea,select,code,pre,noscript"
    );
  }

  function buildTrackedUrl(mappingId, source, target) {
    var url = new URL(window.location.origin + "/");
    url.searchParams.set("lumos_linked_track", "1");
    url.searchParams.set("map", mappingId);
    url.searchParams.set("src", source);
    url.searchParams.set("to", btoa(unescape(encodeURIComponent(target))));
    return url.toString();
  }

  function nextMatch(text, startAt) {
    var best = null;
    for (var i = 0; i < compiled.length; i++) {
      var entry = compiled[i];
      if (isExcluded(entry)) continue;
      entry.regex.lastIndex = startAt;
      var match = entry.regex.exec(text);
      if (!match) continue;
      if (!best || match.index < best.match.index) {
        best = { entry: entry, match: match };
      }
    }
    return best;
  }

  function replaceNode(node) {
    var text = node.nodeValue;
    var cursor = 0;
    var changed = false;
    var fragment = document.createDocumentFragment();
    var source = window.location.href;

    while (cursor < text.length) {
      var found = nextMatch(text, cursor);
      if (!found) break;

      var match = found.match;
      var prefix = match[1] || "";
      var keywordText = match[2] || "";
      var start = match.index;
      var afterPrefix = start + prefix.length;
      var end = start + match[0].length;

      if (afterPrefix < cursor) {
        cursor = end;
        continue;
      }

      if (start > cursor) {
        fragment.appendChild(document.createTextNode(text.slice(cursor, start)));
      }

      if (prefix) {
        fragment.appendChild(document.createTextNode(prefix));
      }

      var a = document.createElement("a");
      a.className = "lumos_link lumos_linked_hover";
      if (hoverStyle === "elara") {
        a.className += " lumos_linked_hover--elara";
      }
      a.href = buildTrackedUrl(found.entry.id, source, found.entry.target);
      var span = document.createElement("span");
      span.textContent = keywordText;
      a.appendChild(span);
      fragment.appendChild(a);

      changed = true;
      cursor = end;
    }

    if (!changed) return;
    if (cursor < text.length) {
      fragment.appendChild(document.createTextNode(text.slice(cursor)));
    }
    node.parentNode.replaceChild(fragment, node);
  }

  function run() {
    if (!document.body) return;
    var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    var textNodes = [];
    var current;
    while ((current = walker.nextNode())) {
      if (!shouldSkipNode(current)) {
        textNodes.push(current);
      }
    }
    textNodes.forEach(replaceNode);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", run);
  } else {
    run();
  }

  // Elementor and other builders can render content after initial load.
  var delayed = [800, 1800, 3500];
  delayed.forEach(function (ms) {
    setTimeout(run, ms);
  });

  var observer = new MutationObserver(function () {
    run();
  });
  if (document.body) {
    observer.observe(document.body, { childList: true, subtree: true });
  }
})();
