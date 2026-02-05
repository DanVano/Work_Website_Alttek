(() => {
  // Footer year
  const yearEl = document.getElementById("year");
  if (yearEl) yearEl.textContent = String(new Date().getFullYear());

  // Mobile nav toggle
  const body = document.body;
  const toggleBtn = document.querySelector(".menu-toggle");
  const mobileNav = document.getElementById("mobileNav");

  function setNavOpen(isOpen) {
    body.classList.toggle("nav-open", isOpen);
    if (toggleBtn) toggleBtn.setAttribute("aria-expanded", isOpen ? "true" : "false");
    if (mobileNav) mobileNav.setAttribute("aria-hidden", isOpen ? "false" : "true");
  }

  if (toggleBtn && mobileNav) {
    toggleBtn.addEventListener("click", () => setNavOpen(!body.classList.contains("nav-open")));

    // Close when clicking any link
    mobileNav.addEventListener("click", (e) => {
      const t = e.target;
      if (t && t.closest && t.closest("a")) setNavOpen(false);
    });

    // Close on ESC
    window.addEventListener("keydown", (e) => {
      if (e.key === "Escape") setNavOpen(false);
    });
  }

  // Contact form submit (POST -> api/contact.php)
  const form = document.querySelector("form[data-contact-form]");
  if (form) {
    const startedAt = Date.now();

    form.addEventListener("submit", async (e) => {
      e.preventDefault();

      const status = document.getElementById("formStatus");
      const submitBtn = form.querySelector('button[type="submit"]');

      const fd = new FormData(form);
      fd.set("started_at", String(startedAt));

      if (submitBtn) submitBtn.disabled = true;
      if (status) {
        status.textContent = "Sending...";
        status.className = "muted";
      }

      try {
        const res = await fetch("api/contact.php", { method: "POST", body: fd });
        const data = await res.json().catch(() => null);

        if (!res.ok || !data || data.ok !== true) {
          const msg = (data && data.error) ? data.error : "Something went wrong. Please try again.";
          throw new Error(msg);
        }

        form.reset();
        if (status) {
          status.textContent = "Message sent. We'll reply soon.";
          status.className = "";
        }
      } catch (err) {
        if (status) {
          status.textContent = err?.message || "Unable to send. Please try again.";
          status.className = "";
        }
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }
})();
