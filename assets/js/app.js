/*
 * Shared shell behaviour: loader, sidebar, AOS and the three.js backdrop.
 */
(function () {
    'use strict';

    /* Loader — dismiss as soon as the DOM is ready; CSS also auto-hides it as a no-JS fallback */
    (function () {
        var loader = document.getElementById('loader');
        if (!loader) { return; }
        function hide() { loader.classList.add('out'); }
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            window.setTimeout(hide, 300);
        } else {
            document.addEventListener('DOMContentLoaded', function () { window.setTimeout(hide, 300); });
        }
        window.addEventListener('load', hide);
    })();

    /*
     * Portals modals out of the .app-main stacking context.
     * .app-main has position:relative + z-index:10, which traps any Bootstrap modal
     * inside it: the body-level backdrop then paints over the dialog and the dialog
     * is clipped to the main column. Moving the nodes to <body> before Bootstrap
     * touches them restores the normal full-viewport modal layering.
     */
    (function () {
        function portalModals() {
            var modals = document.querySelectorAll('.app-main .modal');
            Array.prototype.forEach.call(modals, function (modal) {
                if (modal.parentNode !== document.body) { document.body.appendChild(modal); }
            });
        }

        if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
            var openModal = window.bootstrap.Modal.prototype.show;
            window.bootstrap.Modal.prototype.show = function () {
                if (this._element && this._element.parentNode !== document.body) {
                    document.body.appendChild(this._element);
                }
                return openModal.apply(this, arguments);
            };
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', portalModals);
        } else {
            portalModals();
        }
    })();

    /* Sidebar */
    var sidebar = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var openBtn = document.getElementById('sidebarOpen');
    var closeBtn = document.getElementById('sidebarClose');
    var mobileQuery = window.matchMedia('(max-width: 991.98px)');

    function setSidebar(open) {
        if (!sidebar) { return; }
        sidebar.classList.toggle('open', open);
        if (backdrop) { backdrop.classList.toggle('show', open); }
        if (openBtn) { openBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        document.body.classList.toggle('nav-open', open && mobileQuery.matches);
    }

    if (openBtn) { openBtn.addEventListener('click', function () { setSidebar(true); }); }
    if (closeBtn) { closeBtn.addEventListener('click', function () { setSidebar(false); }); }
    if (backdrop) { backdrop.addEventListener('click', function () { setSidebar(false); }); }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { setSidebar(false); }
    });
    if (sidebar) {
        sidebar.querySelectorAll('a[href]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (mobileQuery.matches) { setSidebar(false); }
                // Show the preloader on the page this sidebar link opens.
                // Modified clicks (new tab/window) are not this document's
                // navigation, so they leave the flag alone.
                if (event.defaultPrevented || event.button !== 0
                    || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
                    || (link.target && link.target !== '_self')) {
                    return;
                }
                try {
                    sessionStorage.setItem('inv:navLoader', String(Date.now()));
                } catch (e) { /* storage unavailable */ }
            });
        });
    }
    mobileQuery.addEventListener('change', function () { setSidebar(false); });

    /* Logout — hold the loader while the sign-out POST is in flight. */
    (function () {
        var signout = document.querySelector('form.signout');
        if (!signout) { return; }
        signout.addEventListener('submit', function () {
            document.documentElement.classList.add('loader-on', 'loader-hold');
            var loader = document.getElementById('loader');
            if (loader) { loader.classList.remove('out'); }
        });
    })();

    /* AOS */
    if (typeof AOS !== 'undefined') {
        AOS.init({
            duration: 700,
            easing: 'ease-out-cubic',
            once: true,
            offset: 60,
            disable: window.matchMedia('(prefers-reduced-motion: reduce)').matches
        });
    } else {
        document.documentElement.classList.add('no-aos');
    }

    /* Three.js background */
    (function () {
        var canvas = document.getElementById('bg-canvas');
        if (!canvas || typeof THREE === 'undefined') { return; }

        var renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.setSize(window.innerWidth, window.innerHeight);

        var scene = new THREE.Scene();
        var camera = new THREE.PerspectiveCamera(60, window.innerWidth / window.innerHeight, 0.1, 100);
        camera.position.z = 22;

        scene.add(new THREE.AmbientLight(0xffffff, 0.2));
        var lightOne = new THREE.PointLight(0xc8e932, 3, 60);
        lightOne.position.set(6, 10, 12);
        scene.add(lightOne);
        var lightTwo = new THREE.PointLight(0x4488ff, 0.8, 40);
        lightTwo.position.set(-10, -6, 8);
        scene.add(lightTwo);
        var lightThree = new THREE.PointLight(0xff9c4b, 0.4, 30);
        lightThree.position.set(12, -8, 5);
        scene.add(lightThree);

        var boxMaterial = new THREE.MeshStandardMaterial({
            color: 0x141820, transparent: true, opacity: 0.65, roughness: 0.2, metalness: 0.9
        });
        var glowMaterial = new THREE.MeshStandardMaterial({
            color: 0xc8e932, transparent: true, opacity: 0.08, emissive: 0xc8e932,
            emissiveIntensity: 0.5, roughness: 0.1, metalness: 1
        });

        var boxes = [];
        for (var i = 0; i < 24; i++) {
            var size = 0.3 + Math.random() * 2.4;
            var geometry = new THREE.BoxGeometry(size, size * (0.65 + Math.random() * 0.7), size * (0.65 + Math.random() * 0.7));
            var isGlow = i % 5 === 0;
            var mesh = new THREE.Mesh(geometry, isGlow ? glowMaterial : boxMaterial);
            var edges = new THREE.EdgesGeometry(geometry);
            var wire = new THREE.LineSegments(edges, new THREE.LineBasicMaterial({
                color: isGlow ? 0xc8e932 : 0x252e40,
                transparent: true,
                opacity: isGlow ? 0.85 : 0.2
            }));
            mesh.add(wire);
            mesh.position.set((Math.random() - 0.5) * 48, (Math.random() - 0.5) * 36, (Math.random() - 0.5) * 20 - 6);
            mesh.rotation.set(Math.random() * Math.PI * 2, Math.random() * Math.PI * 2, Math.random() * Math.PI * 2);
            boxes.push({
                mesh: mesh,
                spin: { x: (Math.random() - 0.5) * 0.006, y: (Math.random() - 0.5) * 0.005 },
                drift: { x: (Math.random() - 0.5) * 0.008, y: (Math.random() - 0.5) * 0.006 }
            });
            scene.add(mesh);
        }

        var mouseX = 0;
        var mouseY = 0;
        document.addEventListener('mousemove', function (event) {
            mouseX = (event.clientX / window.innerWidth - 0.5) * 2;
            mouseY = (event.clientY / window.innerHeight - 0.5) * 2;
        });

        (function animate() {
            window.requestAnimationFrame(animate);
            if (document.hidden) { return; }
            boxes.forEach(function (box) {
                var mesh = box.mesh;
                mesh.rotation.x += box.spin.x;
                mesh.rotation.y += box.spin.y;
                mesh.position.x += box.drift.x;
                mesh.position.y += box.drift.y;
                if (mesh.position.x > 26) { mesh.position.x = -26; }
                if (mesh.position.x < -26) { mesh.position.x = 26; }
                if (mesh.position.y > 20) { mesh.position.y = -20; }
                if (mesh.position.y < -20) { mesh.position.y = 20; }
            });
            camera.position.x += (mouseX * 2 - camera.position.x) * 0.022;
            camera.position.y += (-mouseY * 1.5 - camera.position.y) * 0.022;
            camera.lookAt(0, 0, 0);
            renderer.render(scene, camera);
        })();

        window.addEventListener('resize', function () {
            camera.aspect = window.innerWidth / window.innerHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(window.innerWidth, window.innerHeight);
        });
    })();
})();

/*
 * SweetAlert2 dialogs: confirmation prompts and flash-message toasts.
 */
(function () {
    'use strict';

    var swal = window.Swal || null;

    function confirmAction(message, onConfirm) {
        if (!swal) {
            if (window.confirm(message)) { onConfirm(); }
            return;
        }

        swal.fire({
            title: 'Please confirm',
            text: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, continue',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#c8e932',
            cancelButtonColor: '#6b7280',
            reverseButtons: true,
            focusCancel: true
        }).then(function (result) {
            if (result.isConfirmed) { onConfirm(); }
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') { return; }

        var message = form.getAttribute('data-confirm');
        if (!message) { return; }

        if (form.dataset.confirmApproved === '1') {
            delete form.dataset.confirmApproved;
            return;
        }

        event.preventDefault();
        confirmAction(message, function () {
            form.dataset.confirmApproved = '1';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    });

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest ? event.target.closest('[data-confirm]') : null;
        if (!trigger || trigger.tagName === 'FORM') { return; }

        var message = trigger.getAttribute('data-confirm');
        if (!message) { return; }

        event.preventDefault();
        confirmAction(message, function () {
            var form = trigger.form || (trigger.closest ? trigger.closest('form') : null);
            if (form) {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(trigger.type === 'submit' ? trigger : undefined);
                } else {
                    form.submit();
                }
            } else if (trigger.tagName === 'A' && trigger.href) {
                window.location.href = trigger.href;
            }
        });
    });

    if (!swal) { return; }

    Array.prototype.forEach.call(document.querySelectorAll('.content .alert.alert-dismissible'), function (alertBox) {
        var text = alertBox.textContent.trim();
        if (text === '') { return; }

        var icon = alertBox.classList.contains('alert-danger') ? 'error'
            : alertBox.classList.contains('alert-success') ? 'success'
            : alertBox.classList.contains('alert-warning') ? 'warning'
            : 'info';
        if (icon === 'info' && /\b(success|successfully|saved|added|updated|created|deleted|processed|deactivated|received|recorded)\b/i.test(text)) {
            icon = 'success';
        } else if (icon === 'info' && /fail|unable|error|invalid|not enough|required|incorrect|cannot|denied|unknown/i.test(text)) {
            icon = 'error';
        }

        alertBox.parentNode.removeChild(alertBox);

        swal.fire({
            toast: true,
            position: 'top-end',
            icon: icon,
            title: text,
            showConfirmButton: false,
            timer: icon === 'error' ? 6000 : 4000,
            timerProgressBar: true,
            didOpen: function (toast) {
                toast.addEventListener('mouseenter', swal.stopTimer);
                toast.addEventListener('mouseleave', swal.resumeTimer);
            }
        });
    });
})();
