<?php
/**
 * Archivo encargado de:
 *  - Escuchar el evento "woocommerce_order_status_completed"
 *  - Construir la data de la factura a partir del pedido
 *  - Llamar a la función de envío a la API (factuspress_call_api_to_create_invoice)
 */

if ( ! defined('ABSPATH') ) {
    exit; // Evitar acceso directo
}

// Hook para enviar la factura cuando un pedido se marca como completado
add_action('woocommerce_order_status_completed', 'factuspress_send_invoice_on_order_completed', 10, 1);

/**
 * Función principal que:
 *  1) Obtiene la información del pedido
 *  2) Construye el array (body) con el formato requerido por Factus,
 *     extrayendo datos del cliente desde WooCommerce (company, nombres, dirección, email y phone)
 *  3) Llama a factuspress_call_api_to_create_invoice($body)
 *
 * @param int $order_id ID del pedido.
 */
function factuspress_send_invoice_on_order_completed($order_id) {
    // Obtener el objeto WC_Order
    $order = wc_get_order($order_id);
    if ( ! $order ) {
        return;
    }

    // Extraer datos de facturación del cliente desde el pedido
    $billing_company    = $order->get_billing_company();
    $billing_first_name = $order->get_billing_first_name();
    $billing_last_name  = $order->get_billing_last_name();
    $billing_address    = $order->get_billing_address_1();
    $billing_email      = $order->get_billing_email();
    $billing_phone      = $order->get_billing_phone();
    $billing_identification = $order->get_meta( '_billing_identification' );

    // Obtener ítems del pedido
    $items = $order->get_items();
    $factus_items = [];

    foreach ($items as $item_id => $item) {
        /** @var WC_Order_Item_Product $item */
        $product = $item->get_product();
        if ( ! $product ) {
            continue;
        }

        // Extraer datos del producto
        $product_name  = $item->get_name();
        // Subtotal sin impuestos
        $product_price = $order->get_item_subtotal($item, false);
        $quantity      = $item->get_quantity();

        // Construir cada producto con el formato requerido por Factus
        $factus_items[] = [
            "code_reference"    => (string) $product->get_sku() ?: "SKU-" . $product->get_id(),
            "name"              => $product_name,
            "quantity"          => (int) $quantity,
            "discount_rate"     => 0,           // Ajusta según tu tienda
            "price"             => (float) $product_price,
            "tax_rate"          => "19.00",     // Ajusta según tu configuración
            "unit_measure_id"   => 70,          // Ajusta según tus necesidades
            "standard_code_id"  => 1,
            "is_excluded"       => 0,
            "tribute_id"        => 1,           // Ajusta según corresponda
            "withholding_taxes" => []
        ];
    }

    // Recuperar el valor seleccionado en billing_municipio
    $billing_municipio_id = $order->get_meta('_billing_municipio');

    // Construir el body de la solicitud a Factus, incluyendo los datos del cliente extraídos de WooCommerce
    $body = [
        "reference_code"      => "WC-" . $order_id,  // Referencia única para la factura
        "observation"         => "Pruebas de woocommerce",
        "payment_form"        => "1",
        "payment_due_date"    => "2024-12-30",
        "payment_method_code" => "10",
        "billing_period"      => [
            "start_date" => "2024-01-10",
            "start_time" => "00:00:00",
            "end_date"   => "2024-02-09",
            "end_time"   => "23:59:59"
        ],
        "customer" => [
            "identification"             => $billing_identification ? $billing_identification : "123456789",
            "dv"                         => "3",
            "company"                    => $billing_company,
            "trade_name"                 => "",
            "names"                      => trim($billing_first_name . ' ' . $billing_last_name),
            "address"                    => $billing_address,
            "email"                      => $billing_email,
            "phone"                      => $billing_phone,
            "legal_organization_id"      => "2",
            "tribute_id"                 => "21",
            "identification_document_id" => "3",
            // Aquí seguimos usando el CODE del municipio
            "municipality_id"            => $billing_municipio_id
        ],
        "items" => $factus_items
    ];

    // Registrar en debug.log lo que se va a enviar
    error_log("FactusPress: Datos de Factura con Code de municipio (order_id={$order_id}): " . print_r($body, true));

    // Llamar a la función de envío a la API
    factuspress_call_api_to_create_invoice($body);
}
