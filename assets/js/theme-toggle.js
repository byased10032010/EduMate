// Theme toggle initialization and listeners
(function() {
    const themeToggles = document.querySelectorAll(".theme-switch__checkbox");
    const savedTheme = localStorage.getItem("FutureToppers-theme");
    const prefersDark = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches;
    
    const setTheme = (theme) => {
        document.documentElement.dataset.theme = theme;
        themeToggles.forEach((toggle) => {
            toggle.checked = theme === "dark";
        });
        localStorage.setItem("FutureToppers-theme", theme);
    };

    setTheme(savedTheme || (prefersDark ? "dark" : "light"));

    themeToggles.forEach((toggle) => {
        toggle.addEventListener("change", () => {
            const nextTheme = toggle.checked ? "dark" : "light";
            setTheme(nextTheme);
        });
    });
})();
