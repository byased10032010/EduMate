/**
 * Custom Select Dropdown — replaces native <select> with a fully styleable dropdown
 */
(function () {
    document.querySelectorAll('.custom-select').forEach(function (wrapper) {
        var trigger = wrapper.querySelector('.custom-select__trigger');
        var label = wrapper.querySelector('.custom-select__label');
        var optionsList = wrapper.querySelector('.custom-select__options');
        var hiddenInput = wrapper.querySelector('input[type="hidden"]');
        var options = wrapper.querySelectorAll('.custom-select__options li');

        // Set placeholder class if no value selected
        if (!hiddenInput.value) {
            label.classList.add('placeholder');
        }

        // Toggle open/close
        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            // Close all other open selects
            document.querySelectorAll('.custom-select.open').forEach(function (el) {
                if (el !== wrapper) el.classList.remove('open');
            });
            wrapper.classList.toggle('open');
        });

        // Select an option
        options.forEach(function (option) {
            option.addEventListener('click', function () {
                var value = option.getAttribute('data-value');
                var text = option.textContent.trim();

                // Update hidden input
                hiddenInput.value = value;

                // Update label
                label.textContent = text;
                label.classList.remove('placeholder');

                // Mark as selected
                options.forEach(function (o) { o.classList.remove('selected'); });
                option.classList.add('selected');

                // Close dropdown
                wrapper.classList.remove('open');
            });
        });
    });

    // Close dropdown on outside click
    document.addEventListener('click', function () {
        document.querySelectorAll('.custom-select.open').forEach(function (el) {
            el.classList.remove('open');
        });
    });
})();
