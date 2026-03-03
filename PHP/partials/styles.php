<?php ?>
<style>
    .notification-dropdown {
        position: absolute;
        top: 60px;
        right: 80px;
        width: 350px;
        max-height: 450px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        display: none;
        z-index: 1000;
        overflow: hidden;
    }

    .notification-dropdown.active {
        display: block;
        animation: slideDown 0.3s ease;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .notification-dropdown .header {
        padding: 15px 20px;
        background: linear-gradient(135deg, #4A90E2 0%, #0066CC 100%);
        color: #fff;
        font-weight: 600;
        font-size: 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .notification-dropdown .notification-list {
        max-height: 350px;
        overflow-y: auto;
    }

    .notification-dropdown .notification-item {
        padding: 15px 20px;
        border-bottom: 1px solid #f0f0f0;
        cursor: pointer;
        transition: background 0.2s;
        text-decoration: none;
        display: block;
        color: inherit;
    }

    .notification-dropdown .notification-item:hover {
        background: #f8f9fa;
    }

    .notification-dropdown .notification-item .title {
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 5px;
        font-size: 14px;
    }

    .notification-dropdown .notification-item .desc {
        font-size: 13px;
        color: #7f8c8d;
    }

    .notification-dropdown .notification-item .time {
        font-size: 12px;
        color: #95a5a6;
        margin-top: 5px;
    }

    .notification-dropdown .empty-state {
        padding: 40px 20px;
        text-align: center;
        color: #95a5a6;
    }

    .profile-dropdown {
        position: absolute;
        top: 60px;
        right: 20px;
        width: 250px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        display: none;
        z-index: 1000;
        overflow: hidden;
    }

    .profile-dropdown.active {
        display: block;
        animation: slideDown 0.3s ease;
    }

    .profile-dropdown .profile-header {
        padding: 20px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff;
        text-align: center;
    }

    .profile-dropdown .profile-header img {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        border: 3px solid #fff;
        margin-bottom: 10px;
    }

    .profile-dropdown .profile-header .name {
        font-weight: 600;
        font-size: 16px;
        margin-bottom: 5px;
    }

    .profile-dropdown .profile-header .role {
        font-size: 13px;
        opacity: 0.9;
        text-transform: capitalize;
    }

    .profile-dropdown .profile-menu {
        padding: 10px 0;
    }

    .profile-dropdown .profile-menu a {
        display: flex;
        align-items: center;
        padding: 12px 20px;
        color: #2c3e50;
        text-decoration: none;
        transition: background 0.2s;
        font-size: 14px;
    }

    .profile-dropdown .profile-menu a:hover {
        background: #f8f9fa;
    }

    .profile-dropdown .profile-menu a i {
        margin-right: 12px;
        font-size: 18px;
        color: #4A90E2;
    }

    .profile-dropdown .profile-menu a.logout {
        color: #e74c3c;
        border-top: 1px solid #f0f0f0;
    }

    .profile-dropdown .profile-menu a.logout i {
        color: #e74c3c;
    }

    .popup-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: transparent;
        display: none;
        z-index: 999;
    }

    .popup-overlay.active {
        display: block;
    }

    body {
        background-color: #eeeeee !important;
    }

    .bg-custom {
        background-color: #eeeeee !important;
    }

    .bg-gray-100 {
        background-color: #eeeeee !important;
    }
</style>
