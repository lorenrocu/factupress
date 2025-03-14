<?php
/*
Plugin Name: FactuPress
Description: Integración con la API de Factus para generar facturas en WooCommerce.
Version: 1.0
Author: Lorenzo
*/

// Evitar el acceso directo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Definir constantes
define( 'FACTUSPRESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Incluir los archivos necesarios
require_once FACTUSPRESS_PLUGIN_DIR . 'includes/admin/admin-menu.php';        // Menú y submenú en el panel de administración
require_once FACTUSPRESS_PLUGIN_DIR . 'includes/admin/settings-page.php';     // Página de configuración
require_once FACTUSPRESS_PLUGIN_DIR . 'includes/admin/invoices-page.php';     // Página de configuración
require_once FACTUSPRESS_PLUGIN_DIR . 'includes/api/factus-api.php';          // Lógica de comunicación con la API de Factus
require_once FACTUSPRESS_PLUGIN_DIR . 'includes/api/municipalities-api.php';
require_once FACTUSPRESS_PLUGIN_DIR . 'includes/woocommerce/minicipalities.php';
require_once FACTUSPRESS_PLUGIN_DIR . 'includes/api/factus-woocommerce.php';
require_once FACTUSPRESS_PLUGIN_DIR . 'includes/db/factus-db.php';            // Lógica para la base de datos

// Función de activación del plugin
function factuspress_activate() {
    factuspress_create_table();  // Crear tabla al activar el plugin
}
register_activation_hook(__FILE__, 'factuspress_activate');

// Encola estilos en la administración de WordPress
function factuspress_admin_enqueue_scripts( $hook ) {

    // 1) Verifica en qué página estás (hook).
    //    Si quieres que el CSS se cargue SÓLO en tu(s) página(s) de plugin,
    //    revisa con error_log($hook) o var_dump($hook) qué valor retorna cada pantalla.
    //    Ajusta la comparación a tu menú. Ejemplo:
    //
    // if ( 'toplevel_page_factuspress' !== $hook && 'factuspress_page_invoices' !== $hook ) {
    //    return; 
    // }

    // 2) Encola tu estilo
    wp_enqueue_style(
        'factuspress-admin-style', 
        plugin_dir_url( __FILE__ ) . 'assets/css/style.css', 
        array(), 
        '1.0.0', 
        'all'
    );
}
add_action( 'admin_enqueue_scripts', 'factuspress_admin_enqueue_scripts' );

// Crea input para NIT de colombia en el checkout
add_filter( 'woocommerce_checkout_fields', 'factuspress_custom_checkout_fields_nit' );
function factuspress_custom_checkout_fields_nit( $fields ) {
    // Verifica si existe el campo 'billing_company'
    if ( isset( $fields['billing']['billing_company'] ) ) {
        $priority = 35;
    } else {
        $priority = 25;
    }

    $fields['billing']['billing_identification'] = array(
        'type'        => 'text',
        'label'       => __('CC/NIT', 'factuspress'),
        'placeholder' => __('Ingrese su CC o NIT', 'factuspress'),
        'required'    => false, // Cambia a true si deseas hacerlo obligatorio
        'class'       => array('form-row-wide'),
        'clear'       => true,
        'priority'    => $priority,
    );

    return $fields;
}

add_action( 'woocommerce_checkout_create_order', 'factuspress_save_billing_identification', 10, 2 );
function factuspress_save_billing_identification( $order, $data ) {
    if ( isset( $data['billing_identification'] ) ) {
        $order->update_meta_data( '_billing_identification', sanitize_text_field( $data['billing_identification'] ) );
    }
}


