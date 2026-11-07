<?php
require_once __DIR__ . '/bootstrap.php';

if (auth_check()) {
    redirect('/dashboard');
}

$storeName = APP_NAME;
try {
    $pdo = get_db_connection();
    if ($pdo instanceof PDO) {
        $companyName = $pdo->query('SELECT name FROM companies WHERE is_active = 1 ORDER BY company_id LIMIT 1')->fetchColumn();
        if (is_string($companyName) && trim($companyName) !== '') {
            $storeName = trim($companyName);
        }
    }
} catch (Throwable $exception) {
    $storeName = APP_NAME;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($storeName) ?> — Inventory Management System</title>
    <meta name="description" content="Inventory, sales, expense, banking, approval and financial management system.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&family=JetBrains+Mono:wght@400;500;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css"
        integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/LbrixFETvWa5a6sESd" crossorigin="anonymous">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"
        integrity="sha384-CI3ELBVUz9XQO+97x6nwMDPosPR5XvsxW2ua7N1Xeygeh1IxtgqtCkGfQY9WWdHu" crossorigin="anonymous">
    </script>
    <style>
    *,
    *::before,
    *::after {
        margin: 0;
        padding: 0;
        box-sizing: border-box
    }

    :root {
        --lime: #C8E932;
        --lime-bright: #d4f53d;
        --lime-glow: rgba(200, 233, 50, 0.18);
        --lime-dim: rgba(200, 233, 50, 0.07);
        --dark: #06080c;
        --dark-2: #0c0f16;
        --dark-3: #13181f;
        --dark-4: #1a2030;
        --dark-5: #212840;
        --text: #ffffff;
        --text-2: rgba(255, 255, 255, 0.55);
        --text-3: rgba(255, 255, 255, 0.22);
        --text-4: rgba(255, 255, 255, 0.1);
        --border: rgba(255, 255, 255, 0.055);
        --border-2: rgba(255, 255, 255, 0.09);
        --border-lime: rgba(200, 233, 50, 0.22);
        --red: #ff4b4b;
        --blue: #4b8fff;
        --orange: #ff9c4b;
    }

    /* ═══════════════ LOADER ═══════════════ */
    #loader {
        position: fixed;
        inset: 0;
        z-index: 9999;
        background: var(--dark);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 32px;
        transition: opacity 0.9s ease, visibility 0.9s;
    }

    #loader.out {
        opacity: 0;
        visibility: hidden;
        pointer-events: none
    }

    .ld-brand {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px
    }

    .ld-name {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 56px;
        letter-spacing: 8px;
        color: var(--lime);
        animation: ldGlow 1.8s ease-in-out infinite alternate
    }

    .ld-sub {
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        letter-spacing: 4px;
        color: var(--text-3);
        text-transform: uppercase
    }

    @keyframes ldGlow {
        from {
            text-shadow: 0 0 20px rgba(200, 233, 50, 0.2)
        }

        to {
            text-shadow: 0 0 80px rgba(200, 233, 50, 0.9), 0 0 120px rgba(200, 233, 50, 0.3)
        }
    }

    .ld-spinner {
        position: relative;
        width: 72px;
        height: 72px
    }

    .ld-ring {
        position: absolute;
        inset: 0;
        border-radius: 50%;
        border: 2px solid transparent
    }

    .ld-ring:nth-child(1) {
        border-top-color: var(--lime);
        border-right-color: var(--lime);
        animation: spin 1.1s linear infinite
    }

    .ld-ring:nth-child(2) {
        inset: 10px;
        border-bottom-color: rgba(200, 233, 50, 0.35);
        border-left-color: rgba(200, 233, 50, 0.35);
        animation: spin 0.8s linear infinite reverse
    }

    .ld-ring:nth-child(3) {
        inset: 22px;
        border-top-color: rgba(200, 233, 50, 0.15);
        animation: spin 0.55s linear infinite
    }

    @keyframes spin {
        to {
            transform: rotate(360deg)
        }
    }

    .ld-progress {
        width: 220px;
        height: 1px;
        background: var(--dark-4);
        border-radius: 1px;
        overflow: hidden;
        position: relative
    }

    .ld-progress::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(90deg, transparent, var(--lime), transparent);
        animation: ldScan 1.5s ease forwards
    }

    @keyframes ldScan {
        from {
            transform: translateX(-100%)
        }

        to {
            transform: translateX(100%)
        }
    }

    .ld-fill {
        height: 100%;
        width: 0;
        background: var(--lime);
        animation: ldFill 1.6s cubic-bezier(0.4, 0, 0.2, 1) forwards
    }

    @keyframes ldFill {
        to {
            width: 100%
        }
    }

    .ld-msg {
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        letter-spacing: 3px;
        color: var(--text-3);
        text-transform: uppercase
    }

    /* ═══════════════ BASE ═══════════════ */
    html {
        scroll-behavior: smooth
    }

    body {
        font-family: 'DM Sans', sans-serif;
        background: var(--dark);
        color: var(--text);
        overflow-x: hidden
    }


    #bg-canvas {
        position: fixed;
        inset: 0;
        z-index: 0;
        pointer-events: none
    }

    .noise {
        position: fixed;
        inset: 0;
        z-index: 1;
        pointer-events: none;
        opacity: 0.025;
        background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
        background-size: 128px 128px
    }

    .grid {
        position: fixed;
        inset: 0;
        z-index: 1;
        pointer-events: none;
        background-image: linear-gradient(rgba(200, 233, 50, 0.02) 1px, transparent 1px), linear-gradient(90deg, rgba(200, 233, 50, 0.02) 1px, transparent 1px);
        background-size: 64px 64px
    }

    /* ═══════════════ NAV ═══════════════ */
    nav {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 500;
        padding: 0 72px;
        height: 76px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: rgba(6, 8, 12, 0.75);
        backdrop-filter: blur(28px) saturate(180%);
        border-bottom: 1px solid var(--border);
        transition: all 0.3s
    }

    nav.scrolled {
        height: 64px;
        background: rgba(6, 8, 12, 0.95)
    }

    .nav-logo {
        display: flex;
        align-items: baseline;
        gap: 10px;
        text-decoration: none
    }

    .nav-logo-name {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 30px;
        letter-spacing: 4px;
        color: var(--lime)
    }

    .nav-logo-ver {
        font-family: 'JetBrains Mono', monospace;
        font-size: 8px;
        letter-spacing: 2px;
        color: var(--text-3);
        text-transform: uppercase;
        margin-bottom: 2px
    }

    .nav-links {
        display: flex;
        gap: 44px
    }

    .nav-links a {
        font-size: 13px;
        font-weight: 500;
        color: var(--text-2);
        text-decoration: none;
        letter-spacing: 0.3px;
        transition: color 0.2s;
        position: relative
    }

    .nav-links a::after {
        content: '';
        position: absolute;
        bottom: -4px;
        left: 0;
        right: 0;
        height: 1px;
        background: var(--lime);
        transform: scaleX(0);
        transform-origin: left;
        transition: transform 0.25s
    }

    .nav-links a:hover {
        color: var(--text)
    }

    .nav-links a:hover::after {
        transform: scaleX(1)
    }

    .nav-right {
        display: flex;
        align-items: center;
        gap: 16px
    }

    .nav-status {
        display: flex;
        align-items: center;
        gap: 7px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        letter-spacing: 2px;
        color: var(--text-3)
    }

    .nav-status-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: var(--lime);
        animation: blink 1.4s infinite
    }

    @keyframes blink {

        0%,
        100% {
            opacity: 1
        }

        50% {
            opacity: 0.25
        }
    }

    .btn-signin {
        display: flex;
        align-items: center;
        gap: 8px;
        background: var(--lime);
        color: #06080c;
        font-size: 13px;
        font-weight: 700;
        padding: 10px 26px;
        border-radius: 6px;
        text-decoration: none;
        letter-spacing: 0.3px;
        transition: all 0.25s;
        border: none;
        cursor: pointer
    }

    .btn-signin:hover {
        background: var(--lime-bright);
        box-shadow: 0 0 40px rgba(200, 233, 50, 0.45);
        transform: translateY(-2px)
    }

    /* ═══════════════ HERO ═══════════════ */
    .hero {
        min-height: 100vh;
        padding: 76px 72px 0;
        display: flex;
        align-items: center;
        position: relative;
        z-index: 10;
        overflow: hidden
    }

    .hero-inner {
        max-width: 1340px;
        margin: 0 auto;
        width: 100%;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 64px;
        align-items: center;
        padding: 96px 0 80px
    }

    /* left */
    .hero-badge {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        background: var(--lime-dim);
        border: 1px solid var(--border-lime);
        border-radius: 100px;
        padding: 8px 18px 8px 10px;
        margin-bottom: 36px;
        opacity: 0;
        animation: fadeUp 0.8s 0.3s forwards
    }

    .badge-dot {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: var(--lime);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        color: #06080c;
        font-weight: 800
    }

    .badge-text {
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        letter-spacing: 2px;
        color: var(--lime);
        text-transform: uppercase
    }

    .hero-title {
        font-family: 'Bebas Neue', sans-serif;
        line-height: 0.88;
        letter-spacing: 1px;
        margin-bottom: 32px;
        opacity: 0;
        animation: fadeUp 0.9s 0.45s forwards
    }

    .hero-title .t1 {
        display: block;
        font-size: clamp(72px, 8.5vw, 122px);
        color: var(--text)
    }

    .hero-title .t2 {
        display: block;
        font-size: clamp(72px, 8.5vw, 122px);
        color: var(--lime);
        text-shadow: 0 0 80px rgba(200, 233, 50, 0.45), 0 0 160px rgba(200, 233, 50, 0.15)
    }

    .hero-title .t3 {
        display: block;
        font-size: clamp(72px, 8.5vw, 122px);
        color: transparent;
        -webkit-text-stroke: 1.5px rgba(255, 255, 255, 0.12)
    }

    .hero-desc {
        font-size: 16px;
        line-height: 1.8;
        color: var(--text-2);
        max-width: 500px;
        margin-bottom: 48px;
        font-weight: 300;
        opacity: 0;
        animation: fadeUp 0.9s 0.6s forwards
    }

    .hero-desc strong {
        color: var(--text);
        font-weight: 600
    }

    .hero-btns {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 56px;
        opacity: 0;
        animation: fadeUp 0.9s 0.75s forwards
    }

    .btn-primary {
        display: flex;
        align-items: center;
        gap: 10px;
        background: var(--lime);
        color: #06080c;
        font-family: 'Bebas Neue', sans-serif;
        font-size: 18px;
        letter-spacing: 2px;
        padding: 16px 40px;
        border-radius: 8px;
        text-decoration: none;
        transition: all 0.3s;
        position: relative;
        overflow: hidden;
        cursor: pointer
    }

    .btn-primary::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, transparent 30%, rgba(255, 255, 255, 0.3));
        opacity: 0;
        transition: opacity 0.3s
    }

    .btn-primary:hover {
        transform: translateY(-4px);
        box-shadow: 0 20px 60px rgba(200, 233, 50, 0.45)
    }

    .btn-primary:hover::before {
        opacity: 1
    }

    .btn-outline {
        display: flex;
        align-items: center;
        gap: 10px;
        border: 1px solid var(--border-2);
        color: var(--text-2);
        font-size: 14px;
        font-weight: 500;
        padding: 16px 32px;
        border-radius: 8px;
        text-decoration: none;
        transition: all 0.25s;
        cursor: pointer
    }

    .btn-outline:hover {
        border-color: rgba(255, 255, 255, 0.2);
        color: var(--text);
        background: rgba(255, 255, 255, 0.04)
    }

    .hero-kpis {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0;
        border: 1px solid var(--border);
        border-radius: 12px;
        overflow: hidden;
        opacity: 0;
        animation: fadeUp 0.9s 0.9s forwards
    }

    .kpi {
        padding: 20px 24px;
        border-right: 1px solid var(--border);
        position: relative;
        background: var(--dark-2)
    }

    .kpi:last-child {
        border-right: none
    }

    .kpi::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: var(--lime);
        transform: scaleX(0);
        transition: transform 0.4s
    }

    .kpi:hover::before {
        transform: scaleX(1)
    }

    .kpi-val {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 38px;
        letter-spacing: 1px;
        color: var(--lime);
        line-height: 1
    }

    .kpi-label {
        font-size: 11px;
        color: var(--text-3);
        margin-top: 3px;
        letter-spacing: 0.3px
    }

    /* right — dashboard mockup */
    .hero-visual {
        position: relative;
        opacity: 0;
        animation: fadeIn 1.2s 0.7s forwards
    }

    .visual-glow {
        position: absolute;
        inset: -60px;
        pointer-events: none;
        background: radial-gradient(ellipse at 50% 50%, rgba(200, 233, 50, 0.1) 0%, transparent 68%)
    }

    .mock-card {
        background: rgba(12, 15, 22, 0.96);
        border: 1px solid var(--border-2);
        border-radius: 20px;
        padding: 28px;
        backdrop-filter: blur(24px);
        position: relative;
        overflow: hidden;
        box-shadow: 0 40px 120px rgba(0, 0, 0, 0.6)
    }

    .mock-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 1px;
        background: linear-gradient(90deg, transparent 0%, var(--lime) 50%, transparent 100%);
        opacity: 0.7
    }

    .mock-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px
    }

    .mock-title-row {
        display: flex;
        align-items: center;
        gap: 10px
    }

    .mock-icon {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        background: var(--lime-dim);
        color: var(--lime);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px
    }

    .mock-label {
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        letter-spacing: 2px;
        color: var(--text-3);
        text-transform: uppercase
    }

    .mock-live {
        display: flex;
        align-items: center;
        gap: 6px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 9px;
        letter-spacing: 2px;
        color: var(--lime)
    }

    .mock-live::before {
        content: '';
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: var(--lime);
        animation: blink 1s infinite;
        flex-shrink: 0
    }

    .mock-revenue {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 58px;
        letter-spacing: 2px;
        color: var(--text);
        line-height: 1;
        margin-bottom: 6px
    }

    .mock-revenue .curr {
        color: var(--lime)
    }

    .mock-uptick {
        font-size: 12px;
        color: var(--lime);
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 5px;
        margin-bottom: 22px
    }

    /* mini chart bars */
    .mchart {
        display: flex;
        align-items: flex-end;
        gap: 5px;
        height: 56px;
        margin-bottom: 24px
    }

    .mbar {
        flex: 1;
        border-radius: 3px 3px 0 0;
        background: var(--dark-5)
    }

    .mbar.on {
        background: linear-gradient(to top, var(--lime), rgba(200, 233, 50, 0.4))
    }

    .mbar:nth-child(1) {
        animation: barIn 0.6s 0.1s both
    }

    .mbar:nth-child(2) {
        animation: barIn 0.6s 0.2s both
    }

    .mbar:nth-child(3) {
        animation: barIn 0.6s 0.3s both
    }

    .mbar:nth-child(4) {
        animation: barIn 0.6s 0.4s both
    }

    .mbar:nth-child(5) {
        animation: barIn 0.6s 0.5s both
    }

    .mbar:nth-child(6) {
        animation: barIn 0.6s 0.6s both
    }

    .mbar:nth-child(7) {
        animation: barIn 0.6s 0.7s both
    }

    @keyframes barIn {
        from {
            transform: scaleY(0);
            transform-origin: bottom
        }

        to {
            transform: scaleY(1);
            transform-origin: bottom
        }
    }

    .mock-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 10px;
        margin-bottom: 18px
    }

    .mstat {
        background: var(--dark-3);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 14px
    }

    .mstat-v {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 26px;
        letter-spacing: 1px;
        color: var(--text)
    }

    .mstat-l {
        font-size: 9px;
        color: var(--text-3);
        letter-spacing: 0.5px;
        margin-top: 3px
    }

    .mock-items {
        display: flex;
        flex-direction: column;
        gap: 7px
    }

    .mitem {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        background: var(--dark-3);
        border-radius: 9px;
        border: 1px solid var(--border)
    }

    .mitem-color {
        width: 8px;
        height: 8px;
        border-radius: 2px;
        flex-shrink: 0
    }

    .mitem-name {
        font-size: 12px;
        color: var(--text-2);
        flex: 1
    }

    .mitem-val {
        font-family: 'JetBrains Mono', monospace;
        font-size: 11px;
        color: var(--lime);
        font-weight: 500
    }

    /* floating elements */
    .float-notif {
        position: absolute;
        top: -24px;
        right: -24px;
        background: var(--dark-3);
        border: 1px solid var(--border-lime);
        border-radius: 14px;
        padding: 14px 18px;
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
        animation: float1 3.5s ease-in-out infinite
    }

    .fn-icon {
        width: 36px;
        height: 36px;
        border-radius: 9px;
        background: rgba(200, 233, 50, 0.12);
        color: var(--lime);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px
    }

    .fn-text {
        font-size: 12px;
        color: var(--text-2);
        line-height: 1.5
    }

    .fn-text b {
        color: var(--text);
        font-size: 13px;
        display: block
    }

    .float-alert {
        position: absolute;
        left: -28px;
        top: 50%;
        background: var(--dark-3);
        border: 1px solid rgba(255, 75, 75, 0.3);
        border-radius: 12px;
        padding: 12px 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
        animation: float2 4s ease-in-out infinite 1s
    }

    .fa-icon {
        width: 28px;
        height: 28px;
        border-radius: 7px;
        background: rgba(255, 75, 75, 0.12);
        color: var(--red);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px
    }

    .fa-text {
        font-size: 11px;
        line-height: 1.5
    }

    .fa-text b {
        color: var(--red);
        display: block;
        font-size: 11px
    }

    .float-badge {
        position: absolute;
        bottom: -18px;
        right: 32px;
        background: var(--lime);
        color: #06080c;
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 1px;
        padding: 8px 18px;
        border-radius: 100px;
        box-shadow: 0 8px 36px rgba(200, 233, 50, 0.55);
        animation: float1 3s ease-in-out infinite 0.6s;
        white-space: nowrap
    }

    @keyframes float1 {

        0%,
        100% {
            transform: translateY(0)
        }

        50% {
            transform: translateY(-12px)
        }
    }

    @keyframes float2 {

        0%,
        100% {
            transform: translateY(-50%)
        }

        50% {
            transform: translateY(calc(-50% - 10px))
        }
    }

    /* ═══════════════ TICKER ═══════════════ */
    .ticker {
        position: relative;
        z-index: 10;
        background: var(--lime);
        padding: 16px 0;
        overflow: hidden;
        border-top: 1px solid rgba(200, 233, 50, 0.3);
        border-bottom: 1px solid rgba(200, 233, 50, 0.3)
    }

    .ticker-track {
        display: flex;
        white-space: nowrap;
        animation: tickerScroll 30s linear infinite
    }

    .ticker-track:hover {
        animation-play-state: paused
    }

    @keyframes tickerScroll {
        from {
            transform: translateX(0)
        }

        to {
            transform: translateX(-50%)
        }
    }

    .titem {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        font-family: 'Bebas Neue', sans-serif;
        font-size: 14px;
        letter-spacing: 2.5px;
        color: #06080c;
        padding: 0 36px
    }

    .titem i {
        font-size: 15px
    }

    .tsep {
        color: rgba(0, 0, 0, 0.25);
        font-size: 18px;
        padding: 0 4px
    }

    /* ═══════════════ SECTIONS SHARED ═══════════════ */
    .sec {
        padding: 120px 72px;
        position: relative;
        z-index: 10
    }

    .sec-inner {
        max-width: 1340px;
        margin: 0 auto
    }

    .sec-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        letter-spacing: 3px;
        color: var(--lime);
        text-transform: uppercase;
        margin-bottom: 20px
    }

    .sec-eyebrow::before {
        content: '';
        width: 24px;
        height: 1px;
        background: var(--lime)
    }

    .sec-h {
        font-family: 'Bebas Neue', sans-serif;
        font-size: clamp(52px, 6.5vw, 88px);
        line-height: 0.88;
        letter-spacing: 2px;
        margin-bottom: 72px
    }

    .sec-h .acc {
        color: var(--lime)
    }

    .sec-h .ghost {
        color: transparent;
        -webkit-text-stroke: 1px rgba(255, 255, 255, 0.12)
    }

    /* ═══════════════ FEATURES ═══════════════ */
    .feat-grid {
        display: grid;
        grid-template-columns: repeat(12, 1fr);
        grid-template-rows: auto auto;
        gap: 14px
    }

    .feat {
        background: var(--dark-2);
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 38px;
        position: relative;
        overflow: hidden;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1)
    }

    .feat::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, rgba(200, 233, 50, 0.05) 0%, transparent 60%);
        opacity: 0;
        transition: opacity 0.4s
    }

    .feat:hover {
        border-color: var(--border-lime);
        transform: translateY(-8px);
        box-shadow: 0 32px 80px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(200, 233, 50, 0.12)
    }

    .feat:hover::after {
        opacity: 1
    }

    .feat.c7 {
        grid-column: span 7
    }

    .feat.c5 {
        grid-column: span 5
    }

    .feat.c4 {
        grid-column: span 4
    }

    .feat.c8 {
        grid-column: span 8
    }

    .feat.lime-bg {
        background: var(--lime);
        border-color: var(--lime)
    }

    .feat.lime-bg::after {
        display: none
    }

    .feat.lime-bg:hover {
        box-shadow: 0 32px 80px rgba(200, 233, 50, 0.35);
        transform: translateY(-8px)
    }

    .feat.lime-bg .feat-num,
    .feat.lime-bg .feat-desc {
        color: rgba(0, 0, 0, 0.5)
    }

    .feat.lime-bg .feat-icon {
        background: rgba(0, 0, 0, 0.1);
        color: #06080c
    }

    .feat.lime-bg .feat-name {
        color: #06080c
    }

    .feat.lime-bg .feat-tag {
        background: rgba(0, 0, 0, 0.1);
        color: rgba(0, 0, 0, 0.4);
        border-color: rgba(0, 0, 0, 0.1)
    }

    .feat-num {
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        letter-spacing: 2px;
        color: var(--text-3);
        margin-bottom: 22px
    }

    .feat-icon {
        width: 52px;
        height: 52px;
        border-radius: 13px;
        background: var(--lime-dim);
        color: var(--lime);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin-bottom: 22px;
        flex-shrink: 0
    }

    .feat-name {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 28px;
        letter-spacing: 1px;
        color: var(--text);
        margin-bottom: 12px;
        line-height: 1
    }

    .feat-desc {
        font-size: 14px;
        color: var(--text-2);
        line-height: 1.75;
        font-weight: 300
    }

    .feat-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        margin-top: 22px
    }

    .feat-tag {
        font-family: 'JetBrains Mono', monospace;
        font-size: 9px;
        letter-spacing: 1px;
        padding: 4px 10px;
        border-radius: 4px;
        background: var(--dark-4);
        color: var(--text-3);
        border: 1px solid var(--border)
    }

    /* feat visual — mini bar chart inside card */
    .feat-chart {
        display: flex;
        align-items: flex-end;
        gap: 4px;
        height: 44px;
        margin-top: 20px
    }

    .fc-bar {
        flex: 1;
        border-radius: 2px 2px 0 0;
        background: var(--dark-5);
        transition: height 0.6s ease
    }

    .fc-bar.on {
        background: var(--lime)
    }

    /* ═══════════════ MARQUEE STATS ═══════════════ */
    .stats-strip {
        padding: 0 72px 120px;
        position: relative;
        z-index: 10
    }

    .stats-card {
        background: var(--dark-2);
        border: 1px solid var(--border);
        border-radius: 22px;
        position: relative;
        overflow: hidden
    }

    .stats-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 1px;
        background: linear-gradient(90deg, transparent, var(--lime) 30%, var(--lime) 70%, transparent)
    }

    .stats-card::after {
        content: var(--store-name-bg);
        font-family: 'Bebas Neue', sans-serif;
        font-size: 240px;
        letter-spacing: 12px;
        color: rgba(255, 255, 255, 0.015);
        position: absolute;
        right: -60px;
        top: 50%;
        transform: translateY(-50%);
        line-height: 1;
        pointer-events: none;
        white-space: nowrap
    }

    .stats-row {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        position: relative;
        z-index: 1
    }

    .sstat {
        padding: 52px 48px;
        border-right: 1px solid var(--border);
        position: relative
    }

    .sstat:last-child {
        border-right: none
    }

    .sstat::before {
        content: '';
        position: absolute;
        bottom: 0;
        left: 48px;
        right: 48px;
        height: 1px;
        background: transparent;
        transition: background 0.4s
    }

    .sstat:hover::before {
        background: var(--lime)
    }

    .sstat-n {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 72px;
        letter-spacing: 2px;
        color: var(--lime);
        line-height: 1
    }

    .sstat-l {
        font-size: 15px;
        color: var(--text-2);
        margin-top: 10px;
        font-weight: 300
    }

    .sstat-sub {
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        color: var(--text-3);
        margin-top: 5px;
        letter-spacing: 1px
    }

    /* ═══════════════ HOW IT WORKS ═══════════════ */
    .how-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 2px;
        background: var(--border);
        border-radius: 20px;
        overflow: hidden
    }

    .how-step {
        background: var(--dark-2);
        padding: 52px 44px;
        position: relative;
        transition: background 0.3s
    }

    .how-step:hover {
        background: var(--dark-3)
    }

    .step-n {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 100px;
        letter-spacing: 2px;
        color: var(--dark-4);
        line-height: 1;
        position: absolute;
        top: 16px;
        right: 20px;
        transition: color 0.3s
    }

    .how-step:hover .step-n {
        color: var(--dark-5)
    }

    .step-ico {
        width: 60px;
        height: 60px;
        border-radius: 15px;
        background: var(--lime-dim);
        color: var(--lime);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        margin-bottom: 28px;
        transition: all 0.3s
    }

    .how-step:hover .step-ico {
        background: var(--lime);
        color: #06080c
    }

    .step-title {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 28px;
        letter-spacing: 1px;
        color: var(--text);
        margin-bottom: 14px
    }

    .step-desc {
        font-size: 14px;
        color: var(--text-2);
        line-height: 1.8;
        font-weight: 300
    }

    .step-arrow {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 24px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        letter-spacing: 2px;
        color: var(--lime);
        text-transform: uppercase
    }

    /* ═══════════════ CONTROLS ═══════════════ */
    .testi-sec {
        padding: 0 72px 120px;
        position: relative;
        z-index: 10
    }

    .testi-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 14px
    }

    .tcard {
        background: var(--dark-2);
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 36px;
        position: relative;
        overflow: hidden;
        transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1)
    }

    .tcard:hover {
        border-color: var(--border-lime);
        transform: translateY(-6px)
    }

    .tcard.featured {
        background: var(--dark-3);
        border-color: var(--border-lime);
        grid-column: span 1
    }

    .tcard-quote {
        font-size: 36px;
        color: var(--lime);
        line-height: 1;
        margin-bottom: 16px;
        font-family: 'Bebas Neue', sans-serif
    }

    .tcard-text {
        font-size: 14px;
        color: var(--text-2);
        line-height: 1.8;
        font-weight: 300;
        margin-bottom: 24px
    }

    .tcard-text em {
        color: var(--lime);
        font-style: normal;
        font-weight: 600
    }

    .tcard-user {
        display: flex;
        align-items: center;
        gap: 14px
    }

    .tcard-avatar {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: var(--lime-dim);
        border: 2px solid var(--border-lime);
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Bebas Neue', sans-serif;
        font-size: 18px;
        color: var(--lime)
    }

    .tcard-name {
        font-weight: 600;
        font-size: 14px;
        color: var(--text)
    }

    .tcard-role {
        font-size: 12px;
        color: var(--text-3);
        margin-top: 2px
    }

    .tcard-stars {
        display: flex;
        gap: 3px;
        color: var(--lime);
        font-size: 12px;
        margin-bottom: 6px
    }

    /* ═══════════════ SCREENSHOT SECTION ═══════════════ */
    .preview-sec {
        padding: 0 72px 120px;
        position: relative;
        z-index: 10
    }

    .preview-tabs {
        display: flex;
        gap: 4px;
        margin-bottom: 32px;
        background: var(--dark-3);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 4px;
        width: fit-content
    }

    .ptab {
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        letter-spacing: 1.5px;
        color: var(--text-3);
        padding: 10px 20px;
        border-radius: 7px;
        cursor: pointer;
        text-transform: uppercase;
        transition: all 0.25s
    }

    .ptab.active,
    .ptab:hover {
        background: var(--dark-4);
        color: var(--lime)
    }

    .preview-frame {
        background: var(--dark-2);
        border: 1px solid var(--border-2);
        border-radius: 20px;
        padding: 3px;
        overflow: hidden;
        box-shadow: 0 40px 120px rgba(0, 0, 0, 0.5);
        position: relative
    }

    .preview-frame::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 1px;
        background: linear-gradient(90deg, transparent, var(--lime), transparent);
        opacity: 0.6
    }

    .preview-bar {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 14px 20px;
        border-bottom: 1px solid var(--border);
        background: var(--dark-3)
    }

    .pbar-dots {
        display: flex;
        gap: 6px
    }

    .pbar-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%
    }

    .pbar-dot:nth-child(1) {
        background: #ff5f57
    }

    .pbar-dot:nth-child(2) {
        background: #febc2e
    }

    .pbar-dot:nth-child(3) {
        background: #28c840
    }

    .pbar-url {
        flex: 1;
        background: var(--dark-4);
        border-radius: 6px;
        padding: 6px 16px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        color: var(--text-3);
        letter-spacing: 0.5px
    }

    .preview-content {
        padding: 32px
    }

    .preview-dash {
        display: grid;
        grid-template-columns: 220px 1fr;
        min-height: 380px;
        gap: 0
    }

    .pdash-sidebar {
        background: var(--dark-3);
        border-radius: 12px 0 0 12px;
        border-right: 1px solid var(--border);
        padding: 24px 0
    }

    .pdsb-logo {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 22px;
        letter-spacing: 3px;
        color: var(--lime);
        padding: 0 24px;
        margin-bottom: 28px
    }

    .pdsb-nav {
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 0 12px
    }

    .pdsb-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 8px;
        font-size: 13px;
        color: var(--text-2);
        transition: all 0.2s
    }

    .pdsb-item.active {
        background: var(--lime-dim);
        color: var(--lime);
        border: 1px solid var(--border-lime)
    }

    .pdsb-item i {
        font-size: 15px
    }

    .pdash-main {
        padding: 24px;
        display: flex;
        flex-direction: column;
        gap: 16px
    }

    .pstat-row {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px
    }

    .pstat-card {
        background: var(--dark-3);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 16px
    }

    .psc-val {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 28px;
        letter-spacing: 1px;
        color: var(--text)
    }

    .psc-label {
        font-size: 10px;
        color: var(--text-3);
        margin-top: 3px
    }

    .psc-trend {
        font-size: 10px;
        color: var(--lime);
        margin-top: 4px
    }

    .preview-chart-row {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 12px
    }

    .pchart-card {
        background: var(--dark-3);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 16px
    }

    .pchart-title {
        font-size: 11px;
        color: var(--text-3);
        margin-bottom: 12px;
        font-family: 'JetBrains Mono', monospace;
        letter-spacing: 1px
    }

    .pchart-bars {
        display: flex;
        align-items: flex-end;
        gap: 5px;
        height: 60px
    }

    .pcb {
        flex: 1;
        border-radius: 2px 2px 0 0;
        background: var(--dark-5)
    }

    .pcb.hi {
        background: var(--lime)
    }

    .pcb.mid {
        background: rgba(200, 233, 50, 0.4)
    }

    .ptop-list {
        display: flex;
        flex-direction: column;
        gap: 6px
    }

    .ptop-item {
        display: flex;
        align-items: center;
        gap: 8px
    }

    .ptop-bar-wrap {
        flex: 1;
        height: 5px;
        background: var(--dark-5);
        border-radius: 3px;
        overflow: hidden
    }

    .ptop-bar {
        height: 100%;
        border-radius: 3px;
        background: var(--lime)
    }

    .ptop-val {
        font-family: 'JetBrains Mono', monospace;
        font-size: 9px;
        color: var(--text-3);
        width: 40px;
        text-align: right
    }

    .ptop-name {
        font-size: 10px;
        color: var(--text-2);
        width: 80px
    }

    /* ═══════════════ CTA ═══════════════ */
    .cta-sec {
        padding: 0 72px 120px;
        position: relative;
        z-index: 10
    }

    .cta-wrap {
        background: var(--lime);
        border-radius: 26px;
        padding: 96px 80px;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 60px;
        align-items: center;
        position: relative;
        overflow: hidden
    }

    .cta-wrap::before {
        content: '';
        position: absolute;
        inset: 0;
        background: radial-gradient(ellipse at 80% 50%, rgba(255, 255, 255, 0.12), transparent 60%)
    }

    .cta-wrap::after {
        content: 'LAUNCH';
        font-family: 'Bebas Neue', sans-serif;
        font-size: 300px;
        letter-spacing: 16px;
        color: rgba(0, 0, 0, 0.055);
        position: absolute;
        right: -60px;
        top: 50%;
        transform: translateY(-50%);
        line-height: 1;
        pointer-events: none;
        white-space: nowrap
    }

    .cta-content {
        position: relative;
        z-index: 1
    }

    .cta-tag {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        letter-spacing: 2px;
        color: rgba(0, 0, 0, 0.45);
        text-transform: uppercase;
        margin-bottom: 20px
    }

    .cta-h {
        font-family: 'Bebas Neue', sans-serif;
        font-size: clamp(44px, 5vw, 72px);
        letter-spacing: 2px;
        color: #06080c;
        line-height: 0.9;
        margin-bottom: 16px
    }

    .cta-sub {
        font-size: 16px;
        color: rgba(0, 0, 0, 0.5);
        font-weight: 300;
        max-width: 480px;
        line-height: 1.7
    }

    .cta-actions {
        position: relative;
        z-index: 1;
        display: flex;
        flex-direction: column;
        gap: 14px;
        align-items: flex-end;
        flex-shrink: 0
    }

    .cta-btn-main {
        display: flex;
        align-items: center;
        gap: 12px;
        background: #06080c;
        color: var(--lime);
        font-family: 'Bebas Neue', sans-serif;
        font-size: 20px;
        letter-spacing: 2px;
        padding: 22px 52px;
        border-radius: 12px;
        text-decoration: none;
        white-space: nowrap;
        transition: all 0.3s;
        cursor: pointer;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3)
    }

    .cta-btn-main:hover {
        transform: translateY(-4px);
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45)
    }

    .cta-note {
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        letter-spacing: 1px;
        color: rgba(0, 0, 0, 0.35);
        text-align: center
    }

    /* ═══════════════ FOOTER ═══════════════ */
    footer {
        padding: 52px 72px;
        border-top: 1px solid var(--border);
        position: relative;
        z-index: 10;
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        align-items: center;
        gap: 40px
    }

    .foot-logo {
        font-family: 'Bebas Neue', sans-serif;
        font-size: 28px;
        letter-spacing: 4px;
        color: var(--lime)
    }

    .foot-logo-sub {
        font-family: 'JetBrains Mono', monospace;
        font-size: 9px;
        letter-spacing: 2px;
        color: var(--text-3);
        margin-top: 4px
    }

    .foot-links {
        display: flex;
        gap: 36px;
        justify-content: center
    }

    .foot-links a {
        font-size: 13px;
        color: var(--text-3);
        text-decoration: none;
        transition: color 0.2s;
        letter-spacing: 0.3px
    }

    .foot-links a:hover {
        color: var(--text)
    }

    .foot-right {
        text-align: right
    }

    .foot-copy {
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        letter-spacing: 1px;
        color: var(--text-3)
    }

    .foot-made {
        font-size: 12px;
        color: var(--text-3);
        margin-top: 6px
    }

    /* ═══════════════ UTILS ═══════════════ */
    @keyframes fadeUp {
        from {
            opacity: 0;
            transform: translateY(28px)
        }

        to {
            opacity: 1;
            transform: translateY(0)
        }
    }

    @keyframes fadeIn {
        from {
            opacity: 0
        }

        to {
            opacity: 1
        }
    }

    .reveal {
        opacity: 0;
        transform: translateY(36px);
        transition: opacity 0.85s ease, transform 0.85s ease
    }

    .reveal.up {
        opacity: 1;
        transform: translateY(0)
    }

    .reveal-d1 {
        transition-delay: 0.1s
    }

    .reveal-d2 {
        transition-delay: 0.2s
    }

    .reveal-d3 {
        transition-delay: 0.3s
    }

    .reveal-d4 {
        transition-delay: 0.4s
    }

    @media(max-width:1100px) {
        nav {
            padding: 0 28px
        }

        .nav-links {
            display: none
        }

        .hero {
            padding: 76px 28px 0
        }

        .hero-inner {
            grid-template-columns: 1fr
        }

        .hero-visual {
            display: none
        }

        .sec {
            padding: 80px 28px
        }

        .stats-strip,
        .testi-sec,
        .preview-sec,
        .cta-sec {
            padding-left: 28px;
            padding-right: 28px
        }

        .feat-grid {
            grid-template-columns: 1fr
        }

        .feat.c7,
        .feat.c5,
        .feat.c4,
        .feat.c8 {
            grid-column: span 1
        }

        .stats-row {
            grid-template-columns: 1fr 1fr
        }

        .how-grid {
            grid-template-columns: 1fr
        }

        .testi-grid {
            grid-template-columns: 1fr
        }

        .preview-dash {
            grid-template-columns: 1fr
        }

        .pdash-sidebar {
            display: none
        }

        .cta-wrap {
            grid-template-columns: 1fr;
            text-align: center
        }

        .cta-actions {
            align-items: center
        }

        footer {
            grid-template-columns: 1fr;
            text-align: center
        }

        .foot-links {
            justify-content: center
        }

        .foot-right {
            text-align: center
        }
    }

    @media(max-width:600px) {
        nav {
            padding: 0 18px
        }

        .nav-logo {
            min-width: 0;
            max-width: 190px;
            overflow: hidden
        }

        .nav-logo-name {
            font-size: 22px;
            letter-spacing: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .nav-logo-ver,
        .nav-status {
            display: none
        }

        .ld-name {
            font-size: 32px;
            letter-spacing: 4px;
            text-align: center;
            padding: 0 20px
        }

        .hero {
            padding: 76px 20px 0
        }

        .kpi {
            padding: 14px 12px
        }

        .kpi-val {
            font-size: 28px
        }

        .sec {
            padding: 64px 20px
        }

        .sec-h {
            font-size: clamp(40px, 11vw, 52px);
            margin-bottom: 40px
        }

        .stats-strip,
        .testi-sec,
        .preview-sec,
        .cta-sec {
            padding-left: 20px;
            padding-right: 20px
        }

        .sstat {
            padding: 28px 20px
        }

        .sstat-n {
            font-size: 44px
        }

        .sstat-sub {
            font-size: 9px;
            letter-spacing: 0.5px;
            line-height: 1.5
        }

        .feat {
            padding: 28px 22px
        }

        .how-step {
            padding: 36px 24px
        }

        .tcard {
            padding: 28px 22px
        }

        .preview-content {
            padding: 16px
        }

        .pstat-row {
            grid-template-columns: 1fr 1fr
        }

        .preview-chart-row {
            grid-template-columns: 1fr
        }

        .cta-wrap {
            padding: 56px 28px
        }

        .cta-btn-main {
            padding: 18px 34px;
            font-size: 17px
        }

        footer {
            padding: 40px 20px
        }
    }
    </style>
    <style>
    :root {
        --store-name-bg: "INVENTORY";
    }
    </style>
</head>

<body>

    <!-- Custom Cursor -->

    <!-- LOADER -->
    <div id="loader">
        <div class="ld-brand">
            <div class="ld-name"><?= e($storeName) ?></div>
            <div class="ld-sub">Inventory Management System</div>
        </div>
        <div class="ld-spinner">
            <div class="ld-ring"></div>
            <div class="ld-ring"></div>
            <div class="ld-ring"></div>
        </div>
        <div class="ld-progress">
            <div class="ld-fill"></div>
        </div>
        <div class="ld-msg">Initializing system...</div>
    </div>

    <canvas id="bg-canvas"></canvas>
    <div class="noise"></div>
    <div class="grid"></div>

    <!-- NAV -->
    <nav id="nav">
        <a href="<?= e(url('/')) ?>" class="nav-logo">
            <span class="nav-logo-name"><?= e($storeName) ?></span>
            <span class="nav-logo-ver">v<?= e(APP_VERSION) ?></span>
        </a>
        <div class="nav-links">
            <a href="#features">Features</a>
            <a href="#how">How It Works</a>
            <a href="#stats">Overview</a>
            <a href="#preview">Preview</a>
        </div>
        <div class="nav-right">
            <div class="nav-status"><span class="nav-status-dot"></span> Secure access</div>
            <a href="<?= e(url('/login')) ?>" class="btn-signin"><i class="bi bi-box-arrow-in-right"></i> Sign In</a>
        </div>
    </nav>

    <!-- ═══ HERO ═══ -->
    <section class="hero">
        <div class="hero-inner">
            <div>
                <div class="hero-badge">
                    <div class="badge-dot">✦</div>
                    <div class="badge-text">Inventory · Sales · Finance</div>
                </div>
                <h1 class="hero-title">
                    <span class="t1">SMART</span>
                    <span class="t2">INVENTORY</span>
                    <span class="t3">MANAGEMENT</span>
                </h1>
                <p class="hero-desc">
                    <?= e($storeName) ?> keeps <strong>stock, invoices, expenses and the books</strong>
                    in one system — branch-level inventory, customer payments, double-entry reporting
                    and approvals, with <strong>every change recorded</strong>.
                </p>
                <div class="hero-btns">
                    <a href="<?= e(url('/login')) ?>" class="btn-primary"><i class="bi bi-box-arrow-in-right"></i> Sign
                        In</a>
                    <a href="#features" class="btn-outline"><i class="bi bi-play-circle"></i> Explore Features</a>
                </div>
                <div class="hero-kpis">
                    <div class="kpi">
                        <div class="kpi-val">Stock</div>
                        <div class="kpi-label">By branch</div>
                    </div>
                    <div class="kpi">
                        <div class="kpi-val">Sales</div>
                        <div class="kpi-label">Invoices &amp; payments</div>
                    </div>
                    <div class="kpi">
                        <div class="kpi-val">Books</div>
                        <div class="kpi-label">Double-entry ledger</div>
                    </div>
                </div>
            </div>

            <!-- Hero Visual -->
            <div class="hero-visual">
                <div class="visual-glow"></div>
                <div class="float-notif">
                    <div class="fn-icon"><i class="bi bi-bag-check-fill"></i></div>
                    <div class="fn-text"><b>Stock movement recorded ✓</b>Receipts, issues and adjustments</div>
                </div>
                <div class="mock-card">
                    <div class="mock-top">
                        <div class="mock-title-row">
                            <div class="mock-icon"><i class="bi bi-graph-up"></i></div>
                            <div class="mock-label">Live position</div>
                        </div>
                        <div class="mock-live">Live</div>
                    </div>
                    <div class="mock-revenue">Stock <span class="curr">&rarr;</span> Books</div>
                    <div class="mock-uptick"><i class="bi bi-arrow-up-right"></i> Stock, sales and cash stay in sync
                    </div>
                    <div class="mchart">
                        <div class="mbar" style="height:38%"></div>
                        <div class="mbar" style="height:55%"></div>
                        <div class="mbar" style="height:42%"></div>
                        <div class="mbar" style="height:72%"></div>
                        <div class="mbar" style="height:50%"></div>
                        <div class="mbar" style="height:85%"></div>
                        <div class="mbar on" style="height:100%"></div>
                    </div>
                    <div class="mock-grid">
                        <div class="mstat">
                            <div class="mstat-v">Inventory</div>
                            <div class="mstat-l">BY BRANCH</div>
                        </div>
                        <div class="mstat">
                            <div class="mstat-v">Sales</div>
                            <div class="mstat-l">INVOICES</div>
                        </div>
                        <div class="mstat">
                            <div class="mstat-v">Ledger</div>
                            <div class="mstat-l">POSTINGS</div>
                        </div>
                    </div>
                    <div class="mock-items">
                        <div class="mitem">
                            <div class="mitem-color" style="background:var(--lime)"></div>
                            <div class="mitem-name">Receipts &amp; adjustments</div>
                            <div class="mitem-val">STOCK</div>
                        </div>
                        <div class="mitem">
                            <div class="mitem-color" style="background:var(--blue)"></div>
                            <div class="mitem-name">Customer payments</div>
                            <div class="mitem-val">CASH</div>
                        </div>
                        <div class="mitem">
                            <div class="mitem-color" style="background:var(--orange)"></div>
                            <div class="mitem-name">Journal postings</div>
                            <div class="mitem-val">LEDGER</div>
                        </div>
                    </div>
                </div>
                <div class="float-alert">
                    <div class="fa-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                    <div class="fa-text"><b>Low-stock alert</b>Follows each product's reorder level</div>
                </div>
                <div class="float-badge">● STOCK · SALES · LEDGER</div>
            </div>
        </div>
    </section>

    <!-- TICKER -->
    <div class="ticker">
        <div class="ticker-track">
            <span class="titem"><i class="bi bi-boxes"></i> Real-time Stock Control <span class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-diagram-3"></i> Branch-level Inventory <span
                    class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-file-earmark-text"></i> Sales Invoicing <span
                    class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-cash-coin"></i> Customer Payments <span class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-receipt"></i> Expense Tracking <span class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-bank"></i> Bank Transactions <span class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-check2-square"></i> Approval Workflows <span
                    class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-graph-up-arrow"></i> Financial Reports <span
                    class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-boxes"></i> Real-time Stock Control <span class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-diagram-3"></i> Branch-level Inventory <span
                    class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-file-earmark-text"></i> Sales Invoicing <span
                    class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-cash-coin"></i> Customer Payments <span class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-receipt"></i> Expense Tracking <span class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-bank"></i> Bank Transactions <span class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-check2-square"></i> Approval Workflows <span
                    class="tsep">✦</span></span>
            <span class="titem"><i class="bi bi-graph-up-arrow"></i> Financial Reports <span
                    class="tsep">✦</span></span>
        </div>
    </div>

    <!-- ═══ FEATURES ═══ -->
    <section class="sec" id="features">
        <div class="sec-inner">
            <div class="sec-eyebrow reveal">What We Offer</div>
            <h2 class="sec-h reveal">BUILT FOR <span class="acc">SERIOUS</span><br><span class="ghost">BUSINESS</span>
            </h2>
            <div class="feat-grid">

                <div class="feat lime-bg c7 reveal">
                    <div class="feat-num">01 — INVENTORY</div>
                    <div class="feat-icon"><i class="bi bi-boxes"></i></div>
                    <div class="feat-name">Branch-level Stock Control</div>
                    <div class="feat-desc">Receive, issue and adjust stock per branch. Sales deduct stock inside a
                        transaction, negative stock is refused, and low-stock alerts follow each product's reorder
                        level.</div>
                    <div class="feat-tags"><span class="feat-tag">RECEIPTS</span><span
                            class="feat-tag">ISSUES</span><span class="feat-tag">ADJUSTMENTS</span><span
                            class="feat-tag">LOW STOCK</span></div>
                </div>

                <div class="feat c5 reveal reveal-d1">
                    <div class="feat-num">02 — SALES</div>
                    <div class="feat-icon"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="feat-name">Invoicing &amp; Receivables</div>
                    <div class="feat-desc">Create invoices with line items and record customer payments against them.
                        Status stays in step as pending, partial or paid, and cancellation restocks once only when no
                        payments exist.</div>
                    <div class="feat-tags"><span class="feat-tag">INVOICES</span><span
                            class="feat-tag">PAYMENTS</span><span class="feat-tag">CANCELLATION</span></div>
                    <div class="feat-chart">
                        <div class="fc-bar" style="height:40%"></div>
                        <div class="fc-bar" style="height:65%"></div>
                        <div class="fc-bar on" style="height:80%"></div>
                        <div class="fc-bar" style="height:55%"></div>
                        <div class="fc-bar on" style="height:90%"></div>
                        <div class="fc-bar" style="height:70%"></div>
                        <div class="fc-bar on" style="height:100%"></div>
                    </div>
                </div>

                <div class="feat c4 reveal reveal-d2">
                    <div class="feat-num">03 — REPORTING</div>
                    <div class="feat-icon"><i class="bi bi-graph-up-arrow"></i></div>
                    <div class="feat-name">Financial Reports</div>
                    <div class="feat-desc">Trial balance, general ledger, income statement, balance sheet and cash flow,
                        generated from journal entries and refreshed on demand.</div>
                    <div class="feat-tags"><span class="feat-tag">LEDGER</span><span class="feat-tag">REPORTS</span>
                    </div>
                </div>

                <div class="feat c8 reveal reveal-d1">
                    <div class="feat-num">04 — APPROVALS</div>
                    <div class="feat-icon"><i class="bi bi-check2-square"></i></div>
                    <div class="feat-name">Approval Workflows</div>
                    <div class="feat-desc">Submit expenses, invoices, discounts and payments to workflows with ordered
                        steps. A request becomes approved only when the required approvals are reached, duplicate
                        approvers are refused, and every action records the user, comment, time and IP address.</div>
                    <div class="feat-tags"><span class="feat-tag">WORKFLOWS</span><span
                            class="feat-tag">STEPS</span><span class="feat-tag">ACTION TRAIL</span><span
                            class="feat-tag">NO DUPLICATES</span></div>
                </div>

                <div class="feat c4 reveal reveal-d3">
                    <div class="feat-num">05 — SECURITY</div>
                    <div class="feat-icon"><i class="bi bi-shield-lock-fill"></i></div>
                    <div class="feat-name">Secure Sessions</div>
                    <div class="feat-desc">Session-based sign-in with CSRF-protected forms and a HttpOnly, SameSite
                        session cookie. Module pages require an authenticated session, and signed-in users skip the
                        landing page.</div>
                    <div class="feat-tags"><span class="feat-tag">SESSION</span><span class="feat-tag">CSRF</span><span
                            class="feat-tag">HTTPONLY</span></div>
                </div>

                <div class="feat c4 reveal reveal-d2">
                    <div class="feat-num">06 — FINANCE</div>
                    <div class="feat-icon"><i class="bi bi-bank"></i></div>
                    <div class="feat-name">Banking &amp; Ledger</div>
                    <div class="feat-desc">Track bank accounts and transactions alongside operating expenses and other
                        income. Amounts post to journal entries that feed the ledger and financial reports.</div>
                    <div class="feat-tags"><span class="feat-tag">BANKS</span><span
                            class="feat-tag">EXPENSES</span><span class="feat-tag">JOURNALS</span></div>
                </div>

                <div class="feat c4 reveal reveal-d1">
                    <div class="feat-num">07 — HISTORY</div>
                    <div class="feat-icon"><i class="bi bi-clock-history"></i></div>
                    <div class="feat-name">Change History</div>
                    <div class="feat-desc">An audit log records actions across inventory, sales and finance, together
                        with the user and the time. Approval actions also capture comments and IP address.</div>
                    <div class="feat-tags"><span class="feat-tag">AUDIT LOG</span><span
                            class="feat-tag">USER</span><span class="feat-tag">TIMESTAMP</span></div>
                </div>

            </div>
        </div>
    </section>

    <!-- ═══ STATS ═══ -->
    <section class="stats-strip" id="stats">
        <div class="stats-card reveal">
            <div class="stats-row">
                <div class="sstat">
                    <div class="sstat-n">STOCK</div>
                    <div class="sstat-l">Branch-level quantities</div>
                    <div class="sstat-sub">RECEIPTS · ISSUES · ADJUSTMENTS</div>
                </div>
                <div class="sstat">
                    <div class="sstat-n">SALES</div>
                    <div class="sstat-l">Invoices &amp; payments</div>
                    <div class="sstat-sub">PENDING · PARTIAL · PAID</div>
                </div>
                <div class="sstat">
                    <div class="sstat-n">BOOKS</div>
                    <div class="sstat-l">Journal &amp; reports</div>
                    <div class="sstat-sub">LEDGER · P&amp;L · CASH FLOW</div>
                </div>
                <div class="sstat">
                    <div class="sstat-n">AUDIT</div>
                    <div class="sstat-l">Recorded actions</div>
                    <div class="sstat-sub">USER · TIME · IP</div>
                </div>
            </div>
        </div>
    </section>

    <!-- ═══ HOW IT WORKS ═══ -->
    <section class="sec" id="how" style="padding-top:0">
        <div class="sec-inner">
            <div class="sec-eyebrow reveal">Simple Process</div>
            <h2 class="sec-h reveal">HOW IT <span class="acc">WORKS</span></h2>
            <div class="how-grid reveal">
                <div class="how-step">
                    <div class="step-n">01</div>
                    <div class="step-ico"><i class="bi bi-shield-lock"></i></div>
                    <div class="step-title">Sign In</div>
                    <div class="step-desc">Sign in with your existing account. The landing page sends signed-in users
                        straight to the dashboard and keeps signed-out visitors here.</div>
                    <div class="step-arrow"><i class="bi bi-arrow-right"></i> Secure session</div>
                </div>
                <div class="how-step">
                    <div class="step-n">02</div>
                    <div class="step-ico"><i class="bi bi-box-seam"></i></div>
                    <div class="step-title">Record Operations</div>
                    <div class="step-desc">Record stock receipts, issues and adjustments, raise invoices, take customer
                        payments, and log expenses, income and bank transactions.</div>
                    <div class="step-arrow"><i class="bi bi-arrow-right"></i> Stock stays in step</div>
                </div>
                <div class="how-step">
                    <div class="step-n">03</div>
                    <div class="step-ico"><i class="bi bi-check2-square"></i></div>
                    <div class="step-title">Review &amp; Approve</div>
                    <div class="step-desc">Review the ledger and reports, and move submissions through approval
                        workflows. Every action is written to the audit log.</div>
                    <div class="step-arrow"><i class="bi bi-arrow-right"></i> Full trail</div>
                </div>
            </div>
        </div>
    </section>

    <!-- ═══ CONTROLS ═══ -->
    <section class="testi-sec" id="controls">
        <div class="sec-inner">
            <div class="sec-eyebrow reveal">Built-In Controls</div>
            <h2 class="sec-h reveal">INTEGRITY <span class="acc">BY DESIGN</span><br><span class="ghost">NOT BY
                    LUCK</span></h2>
            <div class="testi-grid">
                <div class="tcard featured reveal">
                    <div class="tcard-quote">"</div>
                    <div class="tcard-text">Sales deduct stock inside a database transaction, and <em>negative stock is
                            refused</em>. Cancelling an invoice restocks once, and only when no payments have been
                        recorded against it.</div>
                    <div class="tcard-user">
                        <div class="tcard-avatar">SI</div>
                        <div>
                            <div class="tcard-name">Stock Integrity</div>
                            <div class="tcard-role">Inventory module</div>
                        </div>
                    </div>
                </div>
                <div class="tcard reveal reveal-d1">
                    <div class="tcard-quote">"</div>
                    <div class="tcard-text">Invoices, payments, expenses, income and bank transactions post to journal
                        entries, so <em>reports come from the same ledger</em> as the underlying records — not a
                        parallel total.</div>
                    <div class="tcard-user">
                        <div class="tcard-avatar">FI</div>
                        <div>
                            <div class="tcard-name">Financial Integrity</div>
                            <div class="tcard-role">Ledger &amp; reports</div>
                        </div>
                    </div>
                </div>
                <div class="tcard reveal reveal-d2">
                    <div class="tcard-quote">"</div>
                    <div class="tcard-text">Changes are written to the audit log with the user and time. Approval
                        actions also record the comment and IP address, and the <em>same approver cannot approve
                            twice</em>.</div>
                    <div class="tcard-user">
                        <div class="tcard-avatar">TR</div>
                        <div>
                            <div class="tcard-name">Traceability</div>
                            <div class="tcard-role">Audit trail</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ═══ PREVIEW ═══ -->
    <section class="preview-sec" id="preview">
        <div class="sec-inner">
            <div class="sec-eyebrow reveal">Live Preview</div>
            <h2 class="sec-h reveal">SEE IT <span class="acc">IN ACTION</span></h2>
            <div class="preview-frame reveal">
                <div class="preview-bar">
                    <div class="pbar-dots">
                        <div class="pbar-dot"></div>
                        <div class="pbar-dot"></div>
                        <div class="pbar-dot"></div>
                    </div>
                    <div class="pbar-url">localhost/inventory/dashboard</div>
                </div>
                <div class="preview-content">
                    <div class="preview-dash">
                        <div class="pdash-sidebar">
                            <div class="pdsb-logo"><?= e($storeName) ?></div>
                            <div class="pdsb-nav">
                                <div class="pdsb-item active"><i class="bi bi-grid-1x2-fill"></i> Dashboard</div>
                                <div class="pdsb-item"><i class="bi bi-boxes"></i> Inventory</div>
                                <div class="pdsb-item"><i class="bi bi-file-earmark-text"></i> Sales</div>
                                <div class="pdsb-item"><i class="bi bi-receipt"></i> Expenses</div>
                                <div class="pdsb-item"><i class="bi bi-bank"></i> Banking</div>
                            </div>
                        </div>
                        <div class="pdash-main">
                            <div class="pstat-row">
                                <div class="pstat-card">
                                    <div class="psc-val">STOCK</div>
                                    <div class="psc-label">BY BRANCH</div>
                                    <div class="psc-trend">Quantities per location</div>
                                </div>
                                <div class="pstat-card">
                                    <div class="psc-val">SALES</div>
                                    <div class="psc-label">INVOICES</div>
                                    <div class="psc-trend">Pending · partial · paid</div>
                                </div>
                                <div class="pstat-card">
                                    <div class="psc-val">BOOKS</div>
                                    <div class="psc-label">LEDGER</div>
                                    <div class="psc-trend">Journal postings</div>
                                </div>
                                <div class="pstat-card">
                                    <div class="psc-val">APPROVALS</div>
                                    <div class="psc-label">WORKFLOWS</div>
                                    <div class="psc-trend">No duplicate approvers</div>
                                </div>
                            </div>
                            <div class="preview-chart-row">
                                <div class="pchart-card">
                                    <div class="pchart-title">RECENT ACTIVITY</div>
                                    <div class="pchart-bars">
                                        <div class="pcb" style="height:40%"></div>
                                        <div class="pcb mid" style="height:60%"></div>
                                        <div class="pcb" style="height:45%"></div>
                                        <div class="pcb mid" style="height:75%"></div>
                                        <div class="pcb" style="height:55%"></div>
                                        <div class="pcb mid" style="height:88%"></div>
                                        <div class="pcb hi" style="height:100%"></div>
                                    </div>
                                </div>
                                <div class="pchart-card">
                                    <div class="pchart-title">RECORD TYPES</div>
                                    <div class="ptop-list">
                                        <div class="ptop-item">
                                            <div class="ptop-name" style="font-size:9px;color:var(--text-2)">Stock
                                                receipt</div>
                                            <div class="ptop-bar-wrap">
                                                <div class="ptop-bar" style="width:90%"></div>
                                            </div>
                                            <div class="ptop-val">STOCK</div>
                                        </div>
                                        <div class="ptop-item">
                                            <div class="ptop-name" style="font-size:9px;color:var(--text-2)">Sales
                                                invoice</div>
                                            <div class="ptop-bar-wrap">
                                                <div class="ptop-bar" style="width:55%"></div>
                                            </div>
                                            <div class="ptop-val">SALE</div>
                                        </div>
                                        <div class="ptop-item">
                                            <div class="ptop-name" style="font-size:9px;color:var(--text-2)">Payment
                                            </div>
                                            <div class="ptop-bar-wrap">
                                                <div class="ptop-bar" style="width:30%"></div>
                                            </div>
                                            <div class="ptop-val">CASH</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ═══ CTA ═══ -->
    <section class="cta-sec">
        <div class="cta-wrap reveal">
            <div class="cta-content">
                <div class="cta-tag"><i class="bi bi-lightning-charge-fill"></i> Ready to sign in?</div>
                <div class="cta-h">KEEP STOCK, SALES<br>AND BOOKS IN STEP</div>
                <div class="cta-sub">Sign in to manage inventory, invoices and the ledger from one dashboard, with every
                    change recorded.</div>
            </div>
            <div class="cta-actions">
                <a href="<?= e(url('/login')) ?>" class="cta-btn-main"><i class="bi bi-arrow-right-circle-fill"></i>
                    SIGN IN</a>
                <div class="cta-note">Existing account required</div>
            </div>
        </div>
    </section>

    <!-- ═══ FOOTER ═══ -->
    <footer>
        <div>
            <div class="foot-logo"><?= e($storeName) ?></div>
            <div class="foot-logo-sub">Inventory Management System</div>
        </div>
        <div class="foot-links">
            <a href="#features">Features</a>
            <a href="#how">How It Works</a>
            <a href="#stats">Overview</a>
            <a href="#preview">Preview</a>
            <a href="<?= e(url('/login')) ?>">Sign In</a>
        </div>
        <div class="foot-right">
            <div class="foot-copy">&copy; <?= date('Y') ?> <?= e($storeName) ?> — Inventory Management System</div>
            <div class="foot-made">Stock &middot; Sales &middot; Finance</div>
        </div>
    </footer>

    <script>
    // ═══ LOADER ═══
    try { sessionStorage.removeItem('inv:navLoader'); } catch (e) {}
    window.addEventListener('load', () => {
        setTimeout(() => document.getElementById('loader').classList.add('out'), 1800)
    });

    // ═══ NAV SCROLL ═══
    window.addEventListener('scroll', () => {
        document.getElementById('nav').classList.toggle('scrolled', scrollY > 60)
    });

    // ═══ THREE.JS — 3D Floating Inventory Boxes ═══
    (function() {
        const canvas = document.getElementById('bg-canvas');
        const renderer = new THREE.WebGLRenderer({
            canvas,
            alpha: true,
            antialias: true
        });
        renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
        renderer.setSize(innerWidth, innerHeight);

        const scene = new THREE.Scene();
        const cam = new THREE.PerspectiveCamera(60, innerWidth / innerHeight, 0.1, 100);
        cam.position.z = 22;

        scene.add(new THREE.AmbientLight(0xffffff, 0.2));
        const l1 = new THREE.PointLight(0xc8e932, 3, 60);
        l1.position.set(6, 10, 12);
        scene.add(l1);
        const l2 = new THREE.PointLight(0x4488ff, 0.8, 40);
        l2.position.set(-10, -6, 8);
        scene.add(l2);
        const l3 = new THREE.PointLight(0xff9c4b, 0.4, 30);
        l3.position.set(12, -8, 5);
        scene.add(l3);

        const boxMat = new THREE.MeshStandardMaterial({
            color: 0x141820,
            transparent: true,
            opacity: 0.65,
            roughness: 0.2,
            metalness: 0.9
        });
        const glowMat = new THREE.MeshStandardMaterial({
            color: 0xc8e932,
            transparent: true,
            opacity: 0.08,
            emissive: 0xc8e932,
            emissiveIntensity: 0.5,
            roughness: 0.1,
            metalness: 1
        });

        const boxes = [];
        const count = 28;
        for (let i = 0; i < count; i++) {
            const s = 0.3 + Math.random() * 2.4;
            const geo = new THREE.BoxGeometry(s, s * (0.65 + Math.random() * 0.7), s * (0.65 + Math.random() *
                0.7));
            const isGlow = i % 5 === 0;
            const mesh = new THREE.Mesh(geo, isGlow ? glowMat : boxMat);
            const edges = new THREE.EdgesGeometry(geo);
            const wire = new THREE.LineSegments(edges, new THREE.LineBasicMaterial({
                color: isGlow ? 0xc8e932 : 0x252e40,
                transparent: true,
                opacity: isGlow ? 0.85 : 0.2
            }));
            mesh.add(wire);
            mesh.position.set((Math.random() - .5) * 48, (Math.random() - .5) * 36, (Math.random() - .5) * 20 - 6);
            mesh.rotation.set(Math.random() * Math.PI * 2, Math.random() * Math.PI * 2, Math.random() * Math.PI *
                2);
            const sp = {
                rx: (Math.random() - .5) * 0.006,
                ry: (Math.random() - .5) * 0.005
            };
            const dr = {
                x: (Math.random() - .5) * 0.008,
                y: (Math.random() - .5) * 0.006
            };
            boxes.push({
                mesh,
                sp,
                dr
            });
            scene.add(mesh);
        }

        let mx = 0,
            my = 0;
        document.addEventListener('mousemove', e => {
            mx = (e.clientX / innerWidth - .5) * 2;
            my = (e.clientY / innerHeight - .5) * 2
        });

        (function anim() {
            requestAnimationFrame(anim);
            boxes.forEach(({
                mesh,
                sp,
                dr
            }) => {
                mesh.rotation.x += sp.rx;
                mesh.rotation.y += sp.ry;
                mesh.position.x += dr.x;
                mesh.position.y += dr.y;
                if (mesh.position.x > 26) mesh.position.x = -26;
                if (mesh.position.x < -26) mesh.position.x = 26;
                if (mesh.position.y > 20) mesh.position.y = -20;
                if (mesh.position.y < -20) mesh.position.y = 20;
            });
            cam.position.x += (mx * 2 - cam.position.x) * 0.022;
            cam.position.y += (-my * 1.5 - cam.position.y) * 0.022;
            cam.lookAt(0, 0, 0);
            renderer.render(scene, cam);
        })();

        window.addEventListener('resize', () => {
            cam.aspect = innerWidth / innerHeight;
            cam.updateProjectionMatrix();
            renderer.setSize(innerWidth, innerHeight);
        });
    })();

    // ═══ SCROLL REVEAL ═══
    const obs = new IntersectionObserver(entries => {
        entries.forEach((e, i) => {
            if (e.isIntersecting) {
                setTimeout(() => e.target.classList.add('up'), i * 80);
                obs.unobserve(e.target)
            }
        });
    }, {
        threshold: 0.08
    });
    document.querySelectorAll('.reveal').forEach(el => obs.observe(el));
    </script>
</body>

</html>