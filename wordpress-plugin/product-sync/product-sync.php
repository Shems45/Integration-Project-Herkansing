<?php

/**
 * Plugin Name: Product Sync
 * Description: Simple product management UI for the integration project.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

function integration_product_sync_table_name()
{
    global $wpdb;
    return $wpdb->prefix . 'integration_products';
}

function integration_product_sync_create_or_update_table()
{
    global $wpdb;

    $table_name = integration_product_sync_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_central_id VARCHAR(36) NULL,
        name VARCHAR(255) NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        quantity INT NOT NULL DEFAULT 0,
        description TEXT NULL,
        available_in_pos TINYINT(1) NOT NULL DEFAULT 1,
        active TINYINT(1) NOT NULL DEFAULT 1,
        sync_status VARCHAR(50) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY product_central_id (product_central_id)
    ) {$charset_collate};";

    dbDelta($sql);
}

function integration_product_sync_activate()
{
    integration_product_sync_create_or_update_table();

    integration_product_sync_ensure_schema();
    integration_product_sync_backfill_product_central_ids();
}
register_activation_hook(__FILE__, 'integration_product_sync_activate');

function integration_product_sync_ensure_schema()
{
    global $wpdb;

    $table_name = integration_product_sync_table_name();
    $table_exists = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $table_name)
    );

    if ($table_exists !== $table_name) {
        integration_product_sync_create_or_update_table();
        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table_name)
        );
        if ($table_exists !== $table_name) {
            return;
        }
    }

    $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}", 0);
    if (!is_array($columns)) {
        $columns = array();
    }

    if (!in_array('product_central_id', $columns, true)) {
        $wpdb->query(
            "ALTER TABLE {$table_name} ADD COLUMN product_central_id VARCHAR(36) NULL AFTER id"
        );
    }

    if (!in_array('available_in_pos', $columns, true)) {
        $wpdb->query(
            "ALTER TABLE {$table_name} ADD COLUMN available_in_pos TINYINT(1) NOT NULL DEFAULT 1 AFTER description"
        );
    }

    if (!in_array('active', $columns, true)) {
        $wpdb->query(
            "ALTER TABLE {$table_name} ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER available_in_pos"
        );
    }

    if (in_array('central_id', $columns, true)) {
        // Migrate legacy IDs once, then remove old column.
        $wpdb->query(
            "UPDATE {$table_name} SET product_central_id = central_id "
            . "WHERE (product_central_id IS NULL OR product_central_id = '') "
            . "AND central_id IS NOT NULL AND central_id <> ''"
        );

        $legacy_index = $wpdb->get_var(
            "SHOW INDEX FROM {$table_name} WHERE Key_name = 'central_id'"
        );
        if ($legacy_index) {
            $wpdb->query("ALTER TABLE {$table_name} DROP INDEX central_id");
        }

        $wpdb->query("ALTER TABLE {$table_name} DROP COLUMN central_id");
    }

    $central_index = $wpdb->get_var(
        "SHOW INDEX FROM {$table_name} WHERE Key_name = 'product_central_id'"
    );
    if (!$central_index) {
        $wpdb->query(
            "ALTER TABLE {$table_name} ADD UNIQUE KEY product_central_id (product_central_id)"
        );
    }
}

function integration_product_sync_build_wp_product_central_id($row_id)
{
    // WP- and ODOO- prefixes avoid collisions when both systems create products independently.
    return sprintf('WP-%06d', (int) $row_id);
}

function integration_product_sync_assign_wp_product_central_id($row_id)
{
    global $wpdb;

    $id = (int) $row_id;
    if ($id <= 0) {
        return '';
    }

    $table_name = integration_product_sync_table_name();
    $existing = $wpdb->get_var(
        $wpdb->prepare("SELECT product_central_id FROM {$table_name} WHERE id = %d", $id)
    );

    if (is_string($existing) && $existing !== '') {
        return $existing;
    }

    // Concurrency-safe generation for WordPress-origin products:
    // use the already committed auto_increment row id and a WP- prefix.
    $product_central_id = integration_product_sync_build_wp_product_central_id($id);
    $result = $wpdb->update(
        $table_name,
        array('product_central_id' => $product_central_id),
        array('id' => $id),
        array('%s'),
        array('%d')
    );

    if ($result === false || $wpdb->last_error !== '') {
        return '';
    }

    return $product_central_id;
}

function integration_product_sync_backfill_product_central_ids()
{
    global $wpdb;

    $table_name = integration_product_sync_table_name();
    // Backfill uses row id so generation is deterministic and avoids max(id)+1 races.
    $wpdb->query(
        "UPDATE {$table_name} "
        . "SET product_central_id = CONCAT('WP-', LPAD(id, 6, '0')) "
        . "WHERE product_central_id IS NULL OR product_central_id = ''"
    );
}

function integration_product_sync_send_event_to_wp_sender($action, $product_id, $product_data = array())
{
    // Prevent infinite loop when updates originate from integration receiver flow.
    if (!empty($product_data['skip_sync'])) {
        return array('ok' => true, 'error' => '');
    }

    $endpoint = 'http://wp_sender:8000/product-event';
    $integration_http_token = getenv('INTEGRATION_HTTP_TOKEN');
    if (!is_string($integration_http_token) || $integration_http_token === '') {
        $integration_http_token = 'school-project-token';
    }

    $payload = array(
        'action' => $action,
        'id' => (int) $product_id,
        'product_central_id' => isset($product_data['product_central_id'])
            ? sanitize_text_field((string) $product_data['product_central_id'])
            : '',
        'name' => isset($product_data['name']) ? sanitize_text_field((string) $product_data['name']) : '',
        'price' => isset($product_data['price']) ? (float) $product_data['price'] : 0,
        'quantity' => isset($product_data['quantity']) ? (int) $product_data['quantity'] : 0,
        'description' => isset($product_data['description']) ? sanitize_text_field((string) $product_data['description']) : '',
        'available_in_pos' => !empty($product_data['available_in_pos']),
        'active' => !empty($product_data['active']),
    );

    // Flow: WordPress hook -> wp_sender -> XML -> RabbitMQ topic exchange -> queue for Odoo receiver.
    $response = wp_remote_post(
        $endpoint,
        array(
            'timeout' => 5,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Integration-Token' => $integration_http_token,
            ),
            'body' => wp_json_encode($payload),
        )
    );

    if (is_wp_error($response)) {
        return array(
            'ok' => false,
            'error' => $response->get_error_message(),
        );
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        return array(
            'ok' => false,
            'error' => 'wp_sender HTTP ' . $status,
        );
    }

    return array('ok' => true, 'error' => '');
}

function integration_product_sync_expected_token()
{
    $token = getenv('WORDPRESS_SYNC_TOKEN');
    if (is_string($token) && $token !== '') {
        return $token;
    }

    return 'school-project-token';
}

function integration_product_sync_get_table_columns($table_name)
{
    global $wpdb;
    $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}", 0);
    if (!is_array($columns)) {
        return array();
    }

    return $columns;
}

function integration_product_sync_apply_odoo_event($payload)
{
    global $wpdb;

    integration_product_sync_ensure_schema();
    $table_name = integration_product_sync_table_name();
    $columns = integration_product_sync_get_table_columns($table_name);

    $action = sanitize_text_field(strtolower((string) ($payload['action'] ?? '')));
    $product_central_id = sanitize_text_field((string) ($payload['product_central_id'] ?? ''));

    if ($product_central_id === '') {
        return new WP_Error('missing_product_central_id', 'product_central_id is required', array('status' => 400));
    }

    if (!in_array($action, array('created', 'updated', 'deleted'), true)) {
        return new WP_Error('invalid_action', 'Invalid action. Use created, updated, or deleted.', array('status' => 400));
    }

    $existing_product = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table_name} WHERE product_central_id = %s", $product_central_id),
        ARRAY_A
    );

    $name = sanitize_text_field((string) ($payload['name'] ?? ''));
    // Prices are treated as EUR and stored as decimal values without euro symbol.
    $price = (float) $payload['price'];
    $quantity = (int) round((float) $payload['quantity']);
    $description = sanitize_text_field((string) ($payload['description'] ?? ''));
    $available_in_pos = !empty($payload['available_in_pos']) ? 1 : 0;
    $active = !empty($payload['active']) ? 1 : 0;

    $supports_active = in_array('active', $columns, true);
    $supports_available_in_pos = in_array('available_in_pos', $columns, true);

    if ($action === 'created' || $action === 'updated') {
        $data = array(
            'product_central_id' => $product_central_id,
            'name' => $name,
            'price' => $price,
            'quantity' => $quantity,
            'description' => $description,
            'sync_status' => 'synced_from_odoo',
        );
        $formats = array('%s', '%s', '%f', '%d', '%s', '%s');

        if ($supports_available_in_pos) {
            $data['available_in_pos'] = $available_in_pos;
            $formats[] = '%d';
        }

        if ($supports_active) {
            $data['active'] = $active;
            $formats[] = '%d';
        }

        if ($existing_product) {
            $result = $wpdb->update(
                $table_name,
                $data,
                array('id' => (int) $existing_product['id']),
                $formats,
                array('%d')
            );

            if ($result === false || $wpdb->last_error !== '') {
                return new WP_Error('db_update_failed', 'Failed to update product from Odoo', array('status' => 500));
            }

            error_log('product-sync: updated product from odoo product_central_id=' . $product_central_id);
            return array('status' => 'updated', 'product_id' => (int) $existing_product['id']);
        }

        $wpdb->insert($table_name, $data, $formats);
        if ($wpdb->last_error !== '' || (int) $wpdb->insert_id <= 0) {
            // Concurrent integration sync with same product_central_id should update existing row.
            if (stripos((string) $wpdb->last_error, 'Duplicate entry') !== false) {
                $existing_after_conflict = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM {$table_name} WHERE product_central_id = %s", $product_central_id),
                    ARRAY_A
                );
                if ($existing_after_conflict) {
                    $result = $wpdb->update(
                        $table_name,
                        $data,
                        array('id' => (int) $existing_after_conflict['id']),
                        $formats,
                        array('%d')
                    );
                    if ($result === false || $wpdb->last_error !== '') {
                        return new WP_Error('db_update_failed', 'Duplicate product_central_id detected; failed to update existing integration product', array('status' => 500));
                    }

                    error_log('product-sync: duplicate integration product_central_id detected, updated existing row product_central_id=' . $product_central_id);
                    return array('status' => 'updated', 'product_id' => (int) $existing_after_conflict['id']);
                }
            }

            return new WP_Error('db_insert_failed', 'Failed to create product from Odoo', array('status' => 500));
        }

        error_log('product-sync: created product from odoo product_central_id=' . $product_central_id);
        return array('status' => 'created', 'product_id' => (int) $wpdb->insert_id);
    }

    // deleted
    if (!$existing_product) {
        error_log('product-sync: delete noop, product not found for product_central_id=' . $product_central_id);
        return array('status' => 'not_found', 'product_id' => 0);
    }

    if ($supports_active) {
        $result = $wpdb->update(
            $table_name,
            array(
                'active' => 0,
                'sync_status' => 'deleted_from_odoo',
            ),
            array('id' => (int) $existing_product['id']),
            array('%d', '%s'),
            array('%d')
        );

        if ($result === false || $wpdb->last_error !== '') {
            return new WP_Error('db_soft_delete_failed', 'Failed to mark product as deleted from Odoo', array('status' => 500));
        }

        error_log('product-sync: soft deleted product from odoo product_central_id=' . $product_central_id);
        return array('status' => 'soft_deleted', 'product_id' => (int) $existing_product['id']);
    }

    $result = $wpdb->delete($table_name, array('id' => (int) $existing_product['id']), array('%d'));
    if ($result === false || $wpdb->last_error !== '') {
        return new WP_Error('db_delete_failed', 'Failed to delete product from Odoo', array('status' => 500));
    }

    error_log('product-sync: hard deleted product from odoo product_central_id=' . $product_central_id);
    return array('status' => 'deleted', 'product_id' => (int) $existing_product['id']);
}

function integration_product_sync_rest_odoo_product_event($request)
{
    $token = (string) $request->get_header('X-Product-Sync-Token');
    if ($token !== integration_product_sync_expected_token()) {
        return new WP_Error('forbidden', 'Invalid sync token', array('status' => 403));
    }

    $params = $request->get_json_params();
    if (!is_array($params)) {
        return new WP_Error('invalid_payload', 'Invalid JSON payload', array('status' => 400));
    }

    $result = integration_product_sync_apply_odoo_event($params);
    if (is_wp_error($result)) {
        return $result;
    }

    return rest_ensure_response(array(
        'ok' => true,
        'result' => $result,
    ));
}

function integration_product_sync_register_rest_routes()
{
    register_rest_route(
        'product-sync/v1',
        '/odoo-product-event',
        array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => 'integration_product_sync_rest_odoo_product_event',
            'permission_callback' => '__return_true',
        )
    );
}
add_action('rest_api_init', 'integration_product_sync_register_rest_routes');

function integration_product_sync_get_products()
{
    global $wpdb;
    $table_name = integration_product_sync_table_name();
    return $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY id DESC");
}

function integration_product_sync_render_products_tbody_rows($products)
{
    if (empty($products)) {
        echo '<tr><td colspan="11">' . esc_html('No products found.') . '</td></tr>';
        return;
    }

    foreach ($products as $product) {
        echo '<tr>';
        echo '<td>' . esc_html((string) $product->id) . '</td>';
        echo '<td>' . esc_html($product->name) . '</td>';
        echo '<td>' . esc_html((string) $product->price) . '</td>';
        echo '<td>' . esc_html((string) $product->quantity) . '</td>';
        echo '<td>' . esc_html($product->description) . '</td>';
        echo '<td>' . esc_html(!empty($product->available_in_pos) ? 'true' : 'false') . '</td>';
        echo '<td>' . esc_html(!empty($product->active) ? 'true' : 'false') . '</td>';
        echo '<td>' . esc_html($product->sync_status) . '</td>';
        echo '<td>' . esc_html($product->created_at) . '</td>';
        echo '<td>' . esc_html($product->updated_at) . '</td>';
        echo '<td>';

        echo '<a href="' . esc_attr(add_query_arg(array('page' => 'integration-product-sync', 'action' => 'edit', 'id' => (int) $product->id), admin_url('admin.php'))) . '"';
        echo ' class="integration-edit-link"';
        echo ' data-id="' . esc_attr((string) $product->id) . '"';
        echo ' data-name="' . esc_attr($product->name) . '"';
        echo ' data-price="' . esc_attr((string) $product->price) . '"';
        echo ' data-quantity="' . esc_attr((string) $product->quantity) . '"';
        echo ' data-description="' . esc_attr($product->description) . '"';
        echo ' data-product_central_id="' . esc_attr(isset($product->product_central_id) ? (string) $product->product_central_id : '') . '"';
        echo ' data-available_in_pos="' . esc_attr(!empty($product->available_in_pos) ? '1' : '0') . '"';
        echo ' data-active="' . esc_attr(!empty($product->active) ? '1' : '0') . '"';
        echo ' data-sync_status="' . esc_attr($product->sync_status) . '">';
        echo esc_html('Edit');
        echo '</a> | ';

        echo '<form method="post" class="integration-delete-form" style="display:inline;">';
        wp_nonce_field('integration_product_sync_action', 'integration_nonce');
        echo '<input type="hidden" name="integration_action" value="delete" />';
        echo '<input type="hidden" name="id" value="' . esc_attr((string) $product->id) . '" />';
        echo '<button type="submit" class="button-link-delete">' . esc_html('Delete') . '</button>';
        echo '</form>';

        echo '</td>';
        echo '</tr>';
    }
}

function integration_product_sync_ajax_response($ok, $message)
{
    $products = integration_product_sync_get_products();
    ob_start();
    integration_product_sync_render_products_tbody_rows($products);
    $rows_html = ob_get_clean();

    wp_send_json(array(
        'ok' => (bool) $ok,
        'message' => (string) $message,
        'rows_html' => (string) $rows_html,
    ));
}

function integration_product_sync_handle_ajax_action()
{
    if (!current_user_can('manage_options')) {
        wp_send_json(array('ok' => false, 'message' => 'Forbidden'), 403);
    }

    check_ajax_referer('integration_product_sync_action', 'integration_nonce');

    global $wpdb;
    $table_name = integration_product_sync_table_name();
    integration_product_sync_ensure_schema();

    $action = sanitize_text_field(wp_unslash($_POST['integration_action'] ?? ''));

    if ($action === 'create') {
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $price = (float) sanitize_text_field(wp_unslash($_POST['price'] ?? '0'));
        $quantity = (int) sanitize_text_field(wp_unslash($_POST['quantity'] ?? '0'));
        $description = sanitize_text_field(wp_unslash($_POST['description'] ?? ''));
        $available_in_pos = !empty(wp_unslash($_POST['available_in_pos'] ?? '')) ? 1 : 0;
        $active = !empty(wp_unslash($_POST['active'] ?? '')) ? 1 : 0;
        $sync_status = 'pending';

        $wpdb->insert(
            $table_name,
            array(
                'name' => $name,
                'price' => $price,
                'quantity' => $quantity,
                'description' => $description,
                'available_in_pos' => $available_in_pos,
                'active' => $active,
                'sync_status' => $sync_status,
            ),
            array('%s', '%f', '%d', '%s', '%d', '%d', '%s')
        );

        if ($wpdb->last_error !== '') {
            integration_product_sync_ajax_response(false, 'Create failed.');
        }

        $product_id = (int) $wpdb->insert_id;
        if ($product_id <= 0) {
            integration_product_sync_ajax_response(false, 'Create failed.');
        }

        $product_central_id = integration_product_sync_assign_wp_product_central_id($product_id);
        if ($product_central_id === '') {
            integration_product_sync_ajax_response(false, 'Create failed. Could not assign concurrency-safe WP product_central_id.');
        }

        $event_payload = array(
            'id' => $product_id,
            'product_central_id' => $product_central_id,
            'name' => $name,
            'price' => $price,
            'quantity' => $quantity,
            'description' => $description,
            'available_in_pos' => (bool) $available_in_pos,
            'active' => (bool) $active,
            'sync_status' => $sync_status,
        );

        $sync_result = integration_product_sync_send_event_to_wp_sender('created', $product_id, $event_payload);
        if (!$sync_result['ok']) {
            $wpdb->update(
                $table_name,
                array('sync_status' => 'sync_failed'),
                array('id' => $product_id),
                array('%s'),
                array('%d')
            );

            integration_product_sync_ajax_response(false, 'Product created locally, but sync failed: ' . $sync_result['error']);
        }

        do_action('integration_product_created', $product_id, $event_payload);

        $wpdb->update(
            $table_name,
            array('sync_status' => 'synced'),
            array('id' => $product_id),
            array('%s'),
            array('%d')
        );

        integration_product_sync_ajax_response(true, 'Product created.');
    }

    if ($action === 'update') {
        $id = (int) sanitize_text_field(wp_unslash($_POST['id'] ?? '0'));
        if ($id <= 0) {
            integration_product_sync_ajax_response(false, 'Update failed. Invalid product ID.');
        }

        $existing_product = $wpdb->get_row(
            $wpdb->prepare("SELECT id, product_central_id FROM {$table_name} WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$existing_product) {
            integration_product_sync_ajax_response(false, 'Update failed. Product not found.');
        }

        $product_central_id = isset($existing_product['product_central_id'])
            ? (string) $existing_product['product_central_id']
            : '';
        if ($product_central_id === '') {
            $product_central_id = integration_product_sync_assign_wp_product_central_id($id);
            if ($product_central_id === '') {
                integration_product_sync_ajax_response(false, 'Update failed. Could not assign concurrency-safe WP product_central_id.');
            }
        }

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $price = (float) sanitize_text_field(wp_unslash($_POST['price'] ?? '0'));
        $quantity = (int) sanitize_text_field(wp_unslash($_POST['quantity'] ?? '0'));
        $description = sanitize_text_field(wp_unslash($_POST['description'] ?? ''));
        $available_in_pos = !empty(wp_unslash($_POST['available_in_pos'] ?? '')) ? 1 : 0;
        $active = !empty(wp_unslash($_POST['active'] ?? '')) ? 1 : 0;
        $sync_status = 'pending';

        $result = $wpdb->update(
            $table_name,
            array(
                'product_central_id' => $product_central_id,
                'name' => $name,
                'price' => $price,
                'quantity' => $quantity,
                'description' => $description,
                'available_in_pos' => $available_in_pos,
                'active' => $active,
                'sync_status' => $sync_status,
            ),
            array('id' => $id),
            array('%s', '%s', '%f', '%d', '%s', '%d', '%d', '%s'),
            array('%d')
        );

        if ($result === false || $wpdb->last_error !== '') {
            if (stripos((string) $wpdb->last_error, 'Duplicate entry') !== false) {
                integration_product_sync_ajax_response(false, 'Update failed. Duplicate product_central_id detected.');
            }
            integration_product_sync_ajax_response(false, 'Update failed.');
        }

        $event_payload = array(
            'id' => $id,
            'product_central_id' => $product_central_id,
            'name' => $name,
            'price' => $price,
            'quantity' => $quantity,
            'description' => $description,
            'available_in_pos' => (bool) $available_in_pos,
            'active' => (bool) $active,
            'sync_status' => $sync_status,
        );

        $sync_result = integration_product_sync_send_event_to_wp_sender('updated', $id, $event_payload);
        if (!$sync_result['ok']) {
            $wpdb->update(
                $table_name,
                array('sync_status' => 'sync_failed'),
                array('id' => $id),
                array('%s'),
                array('%d')
            );

            integration_product_sync_ajax_response(false, 'Product updated locally, but sync failed: ' . $sync_result['error']);
        }

        do_action('integration_product_updated', $id, $event_payload);

        $wpdb->update(
            $table_name,
            array('sync_status' => 'synced'),
            array('id' => $id),
            array('%s'),
            array('%d')
        );

        integration_product_sync_ajax_response(true, 'Product updated.');
    }

    if ($action === 'delete') {
        $id = (int) sanitize_text_field(wp_unslash($_POST['id'] ?? '0'));
        if ($id <= 0) {
            integration_product_sync_ajax_response(false, 'Delete failed. Invalid product ID.');
        }

        $existing_product = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id),
            ARRAY_A
        );

        $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));
        if ($result === false || $wpdb->last_error !== '') {
            integration_product_sync_ajax_response(false, 'Delete failed.');
        }

        $event_payload = array(
            'id' => $id,
            'product_central_id' => isset($existing_product['product_central_id'])
                ? $existing_product['product_central_id']
                : '',
            'name' => isset($existing_product['name']) ? $existing_product['name'] : '',
            'price' => isset($existing_product['price']) ? $existing_product['price'] : 0,
            'quantity' => isset($existing_product['quantity']) ? $existing_product['quantity'] : 0,
            'description' => isset($existing_product['description']) ? $existing_product['description'] : '',
            'sync_status' => isset($existing_product['sync_status']) ? $existing_product['sync_status'] : 'pending',
        );

        $sync_result = integration_product_sync_send_event_to_wp_sender('deleted', $id, $event_payload);
        if (!$sync_result['ok']) {
            integration_product_sync_ajax_response(false, 'Product deleted locally, but sync failed: ' . $sync_result['error']);
        }

        do_action('integration_product_deleted', $id, $event_payload);
        integration_product_sync_ajax_response(true, 'Product deleted.');
    }

    integration_product_sync_ajax_response(false, 'Unknown action.');
}
add_action('wp_ajax_integration_product_sync_ajax_action', 'integration_product_sync_handle_ajax_action');

function integration_product_sync_on_created($product_id, $product_data)
{
    // Legacy hook kept for compatibility. Sync is executed directly in action handler.
}
add_action('integration_product_created', 'integration_product_sync_on_created', 10, 2);

function integration_product_sync_on_updated($product_id, $product_data)
{
    // Legacy hook kept for compatibility. Sync is executed directly in action handler.
}
add_action('integration_product_updated', 'integration_product_sync_on_updated', 10, 2);

function integration_product_sync_on_deleted($product_id, $product_data)
{
    // Legacy hook kept for compatibility. Sync is executed directly in action handler.
}
add_action('integration_product_deleted', 'integration_product_sync_on_deleted', 10, 2);

function integration_product_sync_admin_menu()
{
    add_menu_page(
        'Product Sync',
        'Product Sync',
        'manage_options',
        'integration-product-sync',
        'integration_product_sync_render_page',
        'dashicons-products',
        26
    );
}
add_action('admin_menu', 'integration_product_sync_admin_menu');

function integration_product_sync_redirect_with_message($message)
{
    $url = add_query_arg(
        array(
            'page' => 'integration-product-sync',
            // add_query_arg handles encoding itself.
            'message' => $message,
        ),
        admin_url('admin.php')
    );

    wp_safe_redirect($url);
    exit;
}

function integration_product_sync_handle_actions()
{
    if (!is_admin()) {
        return;
    }

    if (!isset($_GET['page']) || wp_unslash($_GET['page']) !== 'integration-product-sync') {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die(esc_html('You are not allowed to access this page.'));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['integration_action'])) {
        return;
    }

    check_admin_referer('integration_product_sync_action', 'integration_nonce');

    global $wpdb;
    $table_name = integration_product_sync_table_name();
    integration_product_sync_ensure_schema();

    $action = sanitize_text_field(wp_unslash($_POST['integration_action']));

    if ($action === 'create') {
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $price = (float) sanitize_text_field(wp_unslash($_POST['price'] ?? '0'));
        $quantity = (int) sanitize_text_field(wp_unslash($_POST['quantity'] ?? '0'));
        $description = sanitize_text_field(wp_unslash($_POST['description'] ?? ''));
        $available_in_pos = !empty(wp_unslash($_POST['available_in_pos'] ?? '')) ? 1 : 0;
        $active = !empty(wp_unslash($_POST['active'] ?? '')) ? 1 : 0;
        $sync_status = 'pending';

        $wpdb->insert(
            $table_name,
            array(
                'name' => $name,
                'price' => $price,
                'quantity' => $quantity,
                'description' => $description,
                'available_in_pos' => $available_in_pos,
                'active' => $active,
                'sync_status' => $sync_status,
            ),
            array('%s', '%f', '%d', '%s', '%d', '%d', '%s')
        );

        if ($wpdb->last_error !== '') {
            integration_product_sync_redirect_with_message('Create failed.');
        }

        $product_id = (int) $wpdb->insert_id;
        if ($product_id <= 0) {
            integration_product_sync_redirect_with_message('Create failed.');
        }

        $product_central_id = integration_product_sync_assign_wp_product_central_id($product_id);
        if ($product_central_id === '') {
            integration_product_sync_redirect_with_message('Create failed. Could not assign concurrency-safe WP product_central_id.');
        }

        $event_payload = array(
            'id' => $product_id,
            'product_central_id' => $product_central_id,
            'name' => $name,
            'price' => $price,
            'quantity' => $quantity,
            'description' => $description,
            'available_in_pos' => (bool) $available_in_pos,
            'active' => (bool) $active,
            'sync_status' => $sync_status,
        );

        $sync_result = integration_product_sync_send_event_to_wp_sender(
            'created',
            $product_id,
            $event_payload
        );

        if (!$sync_result['ok']) {
            $wpdb->update(
                $table_name,
                array('sync_status' => 'sync_failed'),
                array('id' => $product_id),
                array('%s'),
                array('%d')
            );

            integration_product_sync_redirect_with_message(
                'Product created locally, but sync failed: ' . $sync_result['error']
            );
        }

        // Keep hooks for compatibility.
        do_action('integration_product_created', $product_id, $event_payload);

        $wpdb->update(
            $table_name,
            array('sync_status' => 'synced'),
            array('id' => $product_id),
            array('%s'),
            array('%d')
        );

        integration_product_sync_redirect_with_message('Product created.');
    }

    if ($action === 'update') {
        $id = (int) sanitize_text_field(wp_unslash($_POST['id'] ?? '0'));
        if ($id <= 0) {
            integration_product_sync_redirect_with_message('Update failed. Invalid product ID.');
        }

        $existing_product = $wpdb->get_row(
            $wpdb->prepare("SELECT id, product_central_id FROM {$table_name} WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$existing_product) {
            integration_product_sync_redirect_with_message('Update failed. Product not found.');
        }

        $product_central_id = isset($existing_product['product_central_id'])
            ? (string) $existing_product['product_central_id']
            : '';
        if ($product_central_id === '') {
            $product_central_id = integration_product_sync_assign_wp_product_central_id($id);
            if ($product_central_id === '') {
                integration_product_sync_redirect_with_message('Update failed. Could not assign concurrency-safe WP product_central_id.');
            }
        }

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $price = (float) sanitize_text_field(wp_unslash($_POST['price'] ?? '0'));
        $quantity = (int) sanitize_text_field(wp_unslash($_POST['quantity'] ?? '0'));
        $description = sanitize_text_field(wp_unslash($_POST['description'] ?? ''));
        $available_in_pos = !empty(wp_unslash($_POST['available_in_pos'] ?? '')) ? 1 : 0;
        $active = !empty(wp_unslash($_POST['active'] ?? '')) ? 1 : 0;
        $sync_status = 'pending';

        $result = $wpdb->update(
            $table_name,
            array(
                'product_central_id' => $product_central_id,
                'name' => $name,
                'price' => $price,
                'quantity' => $quantity,
                'description' => $description,
                'available_in_pos' => $available_in_pos,
                'active' => $active,
                'sync_status' => $sync_status,
            ),
            array('id' => $id),
            array('%s', '%s', '%f', '%d', '%s', '%d', '%d', '%s'),
            array('%d')
        );

        if ($result === false || $wpdb->last_error !== '') {
            if (stripos((string) $wpdb->last_error, 'Duplicate entry') !== false) {
                integration_product_sync_redirect_with_message('Update failed. Duplicate product_central_id detected.');
            }
            integration_product_sync_redirect_with_message('Update failed.');
        }

        $event_payload = array(
            'id' => $id,
            'product_central_id' => $product_central_id,
            'name' => $name,
            'price' => $price,
            'quantity' => $quantity,
            'description' => $description,
            'available_in_pos' => (bool) $available_in_pos,
            'active' => (bool) $active,
            'sync_status' => $sync_status,
        );

        $sync_result = integration_product_sync_send_event_to_wp_sender(
            'updated',
            $id,
            $event_payload
        );

        if (!$sync_result['ok']) {
            $wpdb->update(
                $table_name,
                array('sync_status' => 'sync_failed'),
                array('id' => $id),
                array('%s'),
                array('%d')
            );

            integration_product_sync_redirect_with_message(
                'Product updated locally, but sync failed: ' . $sync_result['error']
            );
        }

        // Keep hooks for compatibility.
        do_action('integration_product_updated', $id, $event_payload);

        $wpdb->update(
            $table_name,
            array('sync_status' => 'synced'),
            array('id' => $id),
            array('%s'),
            array('%d')
        );

        integration_product_sync_redirect_with_message('Product updated.');
    }

    if ($action === 'delete') {
        $id = (int) sanitize_text_field(wp_unslash($_POST['id'] ?? '0'));
        if ($id <= 0) {
            integration_product_sync_redirect_with_message('Delete failed. Invalid product ID.');
        }

        $existing_product = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id),
            ARRAY_A
        );

        $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));
        if ($result === false || $wpdb->last_error !== '') {
            integration_product_sync_redirect_with_message('Delete failed.');
        }

        $event_payload = array(
            'id' => $id,
            'product_central_id' => isset($existing_product['product_central_id'])
                ? $existing_product['product_central_id']
                : '',
            'name' => isset($existing_product['name']) ? $existing_product['name'] : '',
            'price' => isset($existing_product['price']) ? $existing_product['price'] : 0,
            'quantity' => isset($existing_product['quantity']) ? $existing_product['quantity'] : 0,
            'description' => isset($existing_product['description']) ? $existing_product['description'] : '',
            'sync_status' => isset($existing_product['sync_status']) ? $existing_product['sync_status'] : 'pending',
        );

        $sync_result = integration_product_sync_send_event_to_wp_sender(
            'deleted',
            $id,
            $event_payload
        );

        if (!$sync_result['ok']) {
            integration_product_sync_redirect_with_message(
                'Product deleted locally, but sync failed: ' . $sync_result['error']
            );
        }

        // Keep hooks for compatibility.
        do_action('integration_product_deleted', $id, $event_payload);

        integration_product_sync_redirect_with_message('Product deleted.');
    }
}
add_action('admin_init', 'integration_product_sync_handle_actions');

function integration_product_sync_render_page()
{
    try {
        integration_product_sync_ensure_schema();

        if (!current_user_can('manage_options')) {
            wp_die(esc_html('You are not allowed to access this page.'));
        }

        global $wpdb;
        $table_name = integration_product_sync_table_name();

        $edit_id = 0;
        if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
            $edit_id = (int) sanitize_text_field(wp_unslash($_GET['id']));
        }

        $editing_product = null;
        if ($edit_id > 0) {
            $editing_product = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $edit_id));
        }

        $products = integration_product_sync_get_products();

        $message = '';
        if (isset($_GET['message'])) {
            $message = sanitize_text_field(wp_unslash($_GET['message']));
        }
?>
    <div class="wrap">
        <h1><?php echo esc_html('Product Sync'); ?></h1>

        <?php if ($message !== '') : ?>
            <div id="integration-product-sync-notice" class="notice notice-success is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php else : ?>
            <div id="integration-product-sync-notice" class="notice" style="display:none;"><p></p></div>
        <?php endif; ?>

        <h2><?php echo esc_html($editing_product ? 'Edit Product' : 'Create Product'); ?></h2>
        <form method="post" id="integration-product-form">
            <?php wp_nonce_field('integration_product_sync_action', 'integration_nonce'); ?>
            <input type="hidden" id="integration_action" name="integration_action" value="<?php echo esc_attr($editing_product ? 'update' : 'create'); ?>" />
            <?php if ($editing_product) : ?>
                <input type="hidden" id="integration_product_id" name="id" value="<?php echo esc_attr((string) $editing_product->id); ?>" />
            <?php else : ?>
                <input type="hidden" id="integration_product_id" name="id" value="" />
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="product_central_id"><?php echo esc_html('Product Central ID'); ?></label></th>
                    <td>
                        <input name="product_central_id" id="product_central_id" type="text" class="regular-text" readonly value="<?php echo esc_attr($editing_product && isset($editing_product->product_central_id) ? (string) $editing_product->product_central_id : 'Auto generated'); ?>" />
                        <p class="description"><?php echo esc_html('Generated automatically and used as the only sync identifier.'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="name"><?php echo esc_html('Name'); ?></label></th>
                    <td><input name="name" id="name" type="text" class="regular-text" required value="<?php echo esc_attr($editing_product ? $editing_product->name : ''); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="price"><?php echo esc_html('Price'); ?></label></th>
                    <td><input name="price" id="price" type="number" step="0.01" min="0" required value="<?php echo esc_attr($editing_product ? (string) $editing_product->price : '0.00'); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="quantity"><?php echo esc_html('Quantity'); ?></label></th>
                    <td><input name="quantity" id="quantity" type="number" min="0" required value="<?php echo esc_attr($editing_product ? (string) $editing_product->quantity : '0'); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="description"><?php echo esc_html('Description'); ?></label></th>
                    <td><input name="description" id="description" type="text" class="regular-text" value="<?php echo esc_attr($editing_product ? $editing_product->description : ''); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html('Available In POS'); ?></th>
                    <td><label><input name="available_in_pos" id="available_in_pos" type="checkbox" value="1" <?php checked(!$editing_product || !isset($editing_product->available_in_pos) || (int) $editing_product->available_in_pos === 1); ?> /> <?php echo esc_html('true'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html('Active'); ?></th>
                    <td><label><input name="active" id="active" type="checkbox" value="1" <?php checked(!$editing_product || !isset($editing_product->active) || (int) $editing_product->active === 1); ?> /> <?php echo esc_html('true'); ?></label></td>
                </tr>
            </table>

            <?php submit_button($editing_product ? 'Update Product' : 'Create Product'); ?>
        </form>

        <hr />

        <h2><?php echo esc_html('Product Overview'); ?></h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php echo esc_html('ID'); ?></th>
                    <th><?php echo esc_html('Name'); ?></th>
                    <th><?php echo esc_html('Price'); ?></th>
                    <th><?php echo esc_html('Quantity'); ?></th>
                    <th><?php echo esc_html('Description'); ?></th>
                    <th><?php echo esc_html('Available In POS'); ?></th>
                    <th><?php echo esc_html('Active'); ?></th>
                    <th><?php echo esc_html('Sync Status'); ?></th>
                    <th><?php echo esc_html('Created At'); ?></th>
                    <th><?php echo esc_html('Updated At'); ?></th>
                    <th><?php echo esc_html('Actions'); ?></th>
                </tr>
            </thead>
            <tbody id="integration-products-tbody">
                <?php integration_product_sync_render_products_tbody_rows($products); ?>
            </tbody>
        </table>
    </div>
    <script>
        (function () {
            const form = document.getElementById('integration-product-form');
            const tbody = document.getElementById('integration-products-tbody');
            const notice = document.getElementById('integration-product-sync-notice');
            const submitButton = form ? form.querySelector('button[type="submit"]') : null;
            const actionInput = document.getElementById('integration_action');
            const idInput = document.getElementById('integration_product_id');
            const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

            if (!form || !tbody) {
                return;
            }

            function showNotice(message, ok) {
                if (!notice) {
                    return;
                }

                notice.style.display = 'block';
                notice.className = 'notice ' + (ok ? 'notice-success' : 'notice-error') + ' is-dismissible';
                const p = notice.querySelector('p');
                if (p) {
                    p.textContent = message;
                }
            }

            function setCreateMode() {
                form.reset();
                actionInput.value = 'create';
                idInput.value = '';
                const centralIdInput = form.querySelector('[name="product_central_id"]');
                if (centralIdInput) {
                    centralIdInput.value = 'Auto generated';
                }
                const availableInPos = form.querySelector('[name="available_in_pos"]');
                const active = form.querySelector('[name="active"]');
                if (availableInPos) {
                    availableInPos.checked = true;
                }
                if (active) {
                    active.checked = true;
                }
                if (submitButton) {
                    submitButton.textContent = 'Create Product';
                }
            }

            function setEditMode(data) {
                actionInput.value = 'update';
                idInput.value = data.id || '';

                const map = {
                    name: 'name',
                    price: 'price',
                    quantity: 'quantity',
                    description: 'description',
                    product_central_id: 'product_central_id'
                };

                Object.keys(map).forEach((key) => {
                    const input = form.querySelector('[name="' + map[key] + '"]');
                    if (input) {
                        input.value = data[key] || '';
                    }
                });

                const availableInPos = form.querySelector('[name="available_in_pos"]');
                const active = form.querySelector('[name="active"]');
                if (availableInPos) {
                    availableInPos.checked = (data.available_in_pos || '1') === '1';
                }
                if (active) {
                    active.checked = (data.active || '1') === '1';
                }

                if (submitButton) {
                    submitButton.textContent = 'Update Product';
                }
            }

            async function postAjax(formData) {
                formData.append('action', 'integration_product_sync_ajax_action');

                const response = await fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                });

                const data = await response.json();
                return data;
            }

            form.addEventListener('submit', async function (event) {
                event.preventDefault();

                const formData = new FormData(form);
                const data = await postAjax(formData);

                if (typeof data.rows_html === 'string') {
                    tbody.innerHTML = data.rows_html;
                }

                showNotice(data.message || 'Done', !!data.ok);
                if (data.ok) {
                    setCreateMode();
                }
            });

            tbody.addEventListener('click', function (event) {
                const editLink = event.target.closest('.integration-edit-link');
                if (!editLink) {
                    return;
                }

                event.preventDefault();
                setEditMode(editLink.dataset);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });

            tbody.addEventListener('submit', async function (event) {
                const deleteForm = event.target.closest('.integration-delete-form');
                if (!deleteForm) {
                    return;
                }

                event.preventDefault();
                const formData = new FormData(deleteForm);
                const data = await postAjax(formData);

                if (typeof data.rows_html === 'string') {
                    tbody.innerHTML = data.rows_html;
                }

                showNotice(data.message || 'Done', !!data.ok);
                if (data.ok) {
                    setCreateMode();
                }
            });
        })();
    </script>
<?php
    } catch (Throwable $e) {
        echo '<div class="wrap"><h1>Product Sync</h1>';
        echo '<div class="notice notice-error"><p>';
        echo esc_html('Product Sync encountered an error: ' . $e->getMessage());
        echo '</p></div></div>';
    }
}
