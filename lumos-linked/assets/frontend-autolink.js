(function () {
  if (typeof window === "undefined" || typeof document === "undefined") {
    return;
  }

  var data = window.LumosLinkedData || {};
  var mappings = Array.isArray(data.mappings) ? data.mappings : [];
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
      var flags = "gu" + (m.case_sensitive ? "" : "i");
      var pattern =
        "(^|[^\\p{L}\\p{N}_])(" +
        escapeRegex(String(m.keyword)) +
        ")(?=[^\\p{L}\\p{N}_]|$)";
      return {
        id: String(m.id),
        keyword: String(m.keyword),
        target: String(m.target_url),
        regex: new RegExp(pattern, flags),
      };
    });

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
    url.searchParams.set("to", btoa(target));
    return url.toString();
  }

  function nextMatch(text, startAt) {
    var best = null;
    for (var i = 0; i < compiled.length; i++) {
      var entry = compiled[i];
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
      a.href = buildTrackedUrl(found.entry.id, source, found.entry.target);
      a.textContent = keywordText;
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
})();
