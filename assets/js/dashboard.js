/* EduMate dashboard - tiny client helpers.
 * No frameworks, no build step. */

(function () {
    'use strict';

    // -------- Flash dismissal (auto-fade after 5s) --------
    document.querySelectorAll('.flash').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .3s ease, transform .3s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-4px)';
            setTimeout(function () { el.remove(); }, 320);
        }, 5000);
    });

    // -------- Modal open/close --------
    document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var sel = btn.getAttribute('data-modal-open');
            var m = document.querySelector(sel);
            if (m) m.classList.add('open');
        });
    });
    document.querySelectorAll('[data-modal-close], .modal-backdrop').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (e.target !== el) return;
            var m = el.closest ? el.closest('.modal-backdrop') : null;
            if (!m) {
                // clicked the backdrop directly
                m = el;
            }
            if (m) m.classList.remove('open');
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop.open').forEach(function (m) {
                m.classList.remove('open');
            });
        }
    });

    // -------- Auto-submit on change for [data-auto-submit] selects --------
    document.querySelectorAll('select[data-auto-submit]').forEach(function (sel) {
        sel.addEventListener('change', function () {
            if (sel.form) sel.form.submit();
        });
    });

    // -------- Confirm-delete forms --------
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            var msg = f.getAttribute('data-confirm') || 'Are you sure?';
            if (!window.confirm(msg)) e.preventDefault();
        });
    });

    // -------- AJAX helper used by the AI tutor --------
    window.edumate = window.edumate || {};
    window.edumate.postJSON = function (url, data) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data || {})
        }).then(function (r) { return r.json(); });
    };

    // -------- Chat auto-scroll --------
    document.querySelectorAll('.chat .messages').forEach(function (m) {
        m.scrollTop = m.scrollHeight;
    });
    // -------- Sidebar Toggle (Responsive) --------
    var sidebarToggle = document.getElementById('sidebarToggle');
    var dash = document.querySelector('.dash');
    if (sidebarToggle && dash) {
        sidebarToggle.addEventListener('click', function (e) {
            e.preventDefault();
            if (window.innerWidth <= 768) {
                dash.classList.toggle('mobile-open');
            } else {
                dash.classList.toggle('collapsed');
                if (dash.classList.contains('collapsed')) {
                    localStorage.setItem('sidebarCollapsed', 'true');
                } else {
                    localStorage.removeItem('sidebarCollapsed');
                }
            }
        });

        // Close mobile sidebar if clicking on the backdrop
        dash.addEventListener('click', function (e) {
            if (window.innerWidth <= 768 && dash.classList.contains('mobile-open')) {
                // If they click exactly on .dash (the backdrop pseudo-element covers it)
                // Actually, pseudo-elements don't fire events directly, but they bubble from the container.
                // A better approach is to check if the click target is the sidebar itself.
                if (!e.target.closest('.sidebar') && !e.target.closest('#sidebarToggle')) {
                    dash.classList.remove('mobile-open');
                }
            }
        });

        // Initialize desktop state on load
        if (window.innerWidth > 768 && localStorage.getItem('sidebarCollapsed') === 'true') {
            dash.classList.add('collapsed');
        }
    }

    // -------- Custom Select UI --------
    const selects = document.querySelectorAll('select.form-control');
    selects.forEach(select => {
        // Hide visually but keep focusable/validatable for HTML5 validation
        select.style.position = 'absolute';
        select.style.opacity = '0';
        select.style.height = '0';
        select.style.width = '0';
        select.style.border = 'none';
        select.style.padding = '0';
        select.style.margin = '0';
        select.style.pointerEvents = 'none';
        
        const wrapper = document.createElement('div');
        wrapper.className = 'custom-select';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'custom-select__trigger';
        
        const label = document.createElement('span');
        label.className = 'custom-select__label';
        // Handle empty selects safely
        label.textContent = select.options.length > 0 && select.selectedIndex >= 0 ? select.options[select.selectedIndex].text : '';
        
        const chevron = document.createElement('div');
        chevron.className = 'custom-select__chevron';
        chevron.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';
        
        trigger.appendChild(label);
        trigger.appendChild(chevron);
        wrapper.appendChild(trigger);
        
        const optionsList = document.createElement('ul');
        optionsList.className = 'custom-select__options';
        
        Array.from(select.options).forEach((option, index) => {
            const li = document.createElement('li');
            li.textContent = option.text;
            if (index === select.selectedIndex) li.classList.add('selected');
            
            li.addEventListener('click', () => {
                select.selectedIndex = index;
                select.dispatchEvent(new Event('change'));
                
                label.textContent = option.text;
                Array.from(optionsList.children).forEach(c => c.classList.remove('selected'));
                li.classList.add('selected');
                wrapper.classList.remove('open');
            });
            optionsList.appendChild(li);
        });
        
        wrapper.appendChild(optionsList);
        
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            document.querySelectorAll('.custom-select').forEach(sel => {
                if (sel !== wrapper) sel.classList.remove('open');
            });
            wrapper.classList.toggle('open');
        });
    });
    
    document.addEventListener('click', () => {
        document.querySelectorAll('.custom-select').forEach(sel => sel.classList.remove('open'));
    });

})();
