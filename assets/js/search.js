(() => {
  const input = document.getElementById("q");
  const btn = document.getElementById("searchBtn");
  const results = document.getElementById("results");

  const pages = [
    { url: "index.html", title: "Home" },
    { url: "about.html", title: "About Us" },
    { url: "technology-services.html", title: "Technology Services" },
    { url: "contact.html", title: "Contact Us" }
  ];

  function getQuery() {
    const params = new URLSearchParams(window.location.search);
    return (params.get("q") || "").trim();
  }

  function setQuery(q) {
    const params = new URLSearchParams(window.location.search);
    if (q) params.set("q", q);
    else params.delete("q");
    const next = `${window.location.pathname}?${params.toString()}`;
    window.history.replaceState({}, "", next);
  }

  function makeSnippet(text, term) {
    const t = text.replace(/\s+/g, " ").trim();
    if (!term) return t.slice(0, 180) + (t.length > 180 ? "…" : "");
    const idx = t.toLowerCase().indexOf(term.toLowerCase());
    if (idx === -1) return t.slice(0, 180) + (t.length > 180 ? "…" : "");
    const start = Math.max(0, idx - 70);
    const end = Math.min(t.length, idx + 110);
    const snip = t.slice(start, end);
    return (start > 0 ? "…" : "") + snip + (end < t.length ? "…" : "");
  }

  async function search(q) {
    if (!results) return;

    results.innerHTML = "";
    if (!q) {
      results.innerHTML = '<p class="muted">Type a keyword and press Search.</p>';
      return;
    }

    const term = q.toLowerCase();
    const matches = [];

    for (const p of pages) {
      try {
        const res = await fetch(p.url, { cache: "no-store" });
        const html = await res.text();
        const doc = new DOMParser().parseFromString(html, "text/html");
        const main = doc.querySelector("main");
        const text = (main ? main.textContent : doc.body.textContent || "").replace(/\s+/g, " ").trim();

        if (text.toLowerCase().includes(term)) {
          matches.push({
            url: p.url,
            title: p.title,
            snippet: makeSnippet(text, q)
          });
        }
      } catch {
        // ignore fetch errors
      }
    }

    if (matches.length === 0) {
      results.innerHTML = '<p class="muted">No results found.</p>';
      return;
    }

    for (const m of matches) {
      const div = document.createElement("div");
      div.className = "search-result";
      div.innerHTML = `
        <a href="${m.url}">${m.title}</a>
        <div class="muted">${m.snippet}</div>
      `;
      results.appendChild(div);
    }
  }

  function runFromInput() {
    const q = (input?.value || "").trim();
    setQuery(q);
    search(q);
  }

  // Init
  const initial = getQuery();
  if (input) input.value = initial;
  search(initial);

  btn?.addEventListener("click", runFromInput);
  input?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") runFromInput();
  });
})();
