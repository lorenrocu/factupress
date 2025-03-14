<?php
// Registrar el menú y submenú "FactuPress"
function factuspress_register_menu() {
    add_menu_page(
        'FactuPress Settings', // Título del menú
        'FactuPress',          // Nombre que se muestra en el menú
        'manage_options',      // Permiso necesario
        'factupress_settings', // Slug del menú
        'factuspress_settings_page' // Función que muestra la página de configuración
    );

    // Submenú para Facturas
    add_submenu_page(
        'factupress_settings',     // Menú principal
        'Facturas',                // Título de la página
        'Facturas',                // Nombre del submenú
        'manage_options',          // Permisos
        'factuspress_invoices',    // Slug del submenú
        'factuspress_invoices_page' // Función que muestra la página de facturas
    );
}
add_action( 'admin_menu', 'factuspress_register_menu' );
