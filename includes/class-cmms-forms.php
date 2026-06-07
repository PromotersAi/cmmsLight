<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Forms {

    public static function field_types() {
        return array(
            'text'     => __( 'Short text', 'cmms-light' ),
            'textarea' => __( 'Long text', 'cmms-light' ),
            'date'     => __( 'Date', 'cmms-light' ),
            'dropdown' => __( 'Dropdown', 'cmms-light' ),
            'checkbox' => __( 'Checkbox', 'cmms-light' ),
            'file'     => __( 'File / Image', 'cmms-light' ),
        );
    }

    public static function create_form( $account_id, $data ) {
        global $wpdb;
        $table = CMMS_DB::table( 'forms' );
        $name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
        if ( empty( $name ) ) return new WP_Error( 'invalid', __( 'Name required', 'cmms-light' ) );

        $slug = self::unique_slug( sanitize_title( $name ) );

        $wpdb->insert( $table, array(
            'account_id'           => intval( $account_id ),
            'name'                 => $name,
            'description'          => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
            'slug'                 => $slug,
            'manager_id'           => isset( $data['manager_id'] ) ? intval( $data['manager_id'] ) : null,
            'default_assignee_id'  => isset( $data['default_assignee_id'] ) ? intval( $data['default_assignee_id'] ) : null,
            'default_category_id'  => isset( $data['default_category_id'] ) ? intval( $data['default_category_id'] ) : null,
            'default_priority'     => isset( $data['default_priority'] ) ? sanitize_text_field( $data['default_priority'] ) : 'normal',
            'default_status'       => isset( $data['default_status'] ) ? sanitize_text_field( $data['default_status'] ) : 'open',
            'enabled'              => 1,
            'created_by'           => isset( $data['created_by'] ) ? intval( $data['created_by'] ) : null,
            'created_at'           => current_time( 'mysql' ),
        ), array( '%d','%s','%s','%s','%d','%d','%d','%s','%s','%d','%d','%s' ) );
        return $wpdb->insert_id;
    }

    public static function update_form( $id, $data ) {
        global $wpdb;
        $table = CMMS_DB::table( 'forms' );
        $update = array();
        $format = array();
        $map = array(
            'name' => '%s', 'description' => '%s', 'manager_id' => '%d',
            'default_assignee_id' => '%d',
            'default_category_id' => '%d', 'default_priority' => '%s', 'default_status' => '%s', 'enabled' => '%d',
        );
        foreach ( $map as $k => $fmt ) {
            if ( ! array_key_exists( $k, $data ) ) continue;
            $val = $data[ $k ];
            if ( $fmt === '%s' ) $val = sanitize_text_field( $val );
            elseif ( $fmt === '%d' ) $val = $val !== '' ? intval( $val ) : null;
            $update[ $k ] = $val;
            $format[] = $fmt;
        }
        if ( empty( $update ) ) return false;
        return $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );
    }

    public static function delete_form( $id ) {
        global $wpdb;
        $wpdb->delete( CMMS_DB::table( 'form_fields' ), array( 'form_id' => $id ), array( '%d' ) );
        $wpdb->delete( CMMS_DB::table( 'forms' ), array( 'id' => $id ), array( '%d' ) );
    }

    public static function get( $id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'forms' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
    }

    public static function get_by_slug( $slug ) {
        global $wpdb;
        $table = CMMS_DB::table( 'forms' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE slug = %s", $slug ) );
    }

    public static function list_by_account( $account_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'forms' );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE account_id = %d ORDER BY created_at DESC", $account_id ) );
    }

    public static function add_field( $form_id, $data ) {
        global $wpdb;
        $table = CMMS_DB::table( 'form_fields' );
        $type = isset( $data['field_type'] ) ? sanitize_text_field( $data['field_type'] ) : 'text';
        if ( ! array_key_exists( $type, self::field_types() ) ) $type = 'text';
        $label = isset( $data['label'] ) ? sanitize_text_field( $data['label'] ) : '';
        if ( empty( $label ) ) return false;
        $options = isset( $data['options'] ) ? sanitize_textarea_field( $data['options'] ) : '';
        $required = ! empty( $data['required'] ) ? 1 : 0;
        $sort = isset( $data['sort_order'] ) ? intval( $data['sort_order'] ) : 0;

        $wpdb->insert( $table, array(
            'form_id'    => intval( $form_id ),
            'label'      => $label,
            'field_type' => $type,
            'options'    => $options,
            'required'   => $required,
            'sort_order' => $sort,
        ), array( '%d', '%s', '%s', '%s', '%d', '%d' ) );
        return $wpdb->insert_id;
    }

    public static function update_field( $id, $data ) {
        global $wpdb;
        $table = CMMS_DB::table( 'form_fields' );
        $update = array(); $format = array();
        if ( isset( $data['label'] ) ) { $update['label'] = sanitize_text_field( $data['label'] ); $format[] = '%s'; }
        if ( isset( $data['field_type'] ) ) {
            $type = sanitize_text_field( $data['field_type'] );
            if ( array_key_exists( $type, self::field_types() ) ) {
                $update['field_type'] = $type; $format[] = '%s';
            }
        }
        if ( isset( $data['options'] ) ) { $update['options'] = sanitize_textarea_field( $data['options'] ); $format[] = '%s'; }
        if ( isset( $data['required'] ) ) { $update['required'] = $data['required'] ? 1 : 0; $format[] = '%d'; }
        if ( isset( $data['sort_order'] ) ) { $update['sort_order'] = intval( $data['sort_order'] ); $format[] = '%d'; }
        if ( empty( $update ) ) return false;
        return $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );
    }

    public static function delete_field( $id ) {
        global $wpdb;
        $wpdb->delete( CMMS_DB::table( 'form_fields' ), array( 'id' => $id ), array( '%d' ) );
    }

    public static function get_fields( $form_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'form_fields' );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE form_id = %d ORDER BY sort_order ASC, id ASC", $form_id ) );
    }

    public static function get_field( $id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'form_fields' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
    }

    public static function form_public_url( $form ) {
        if ( ! $form ) return '';
        return add_query_arg( array( 'cmms_form' => $form->slug ), home_url( '/cmms-form/' ) );
    }

    public static function qr_url( $form ) {
        $url = self::form_public_url( $form );
        return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode( $url );
    }

    private static function unique_slug( $base ) {
        global $wpdb;
        $table = CMMS_DB::table( 'forms' );
        if ( empty( $base ) ) $base = 'form';
        $slug = $base;
        $i = 1;
        while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE slug = %s", $slug ) ) ) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    /**
     * Process a public form submission.
     *
     * @param string $slug          Form slug.
     * @param array  $post_data     Field values keyed by 'field_<id>'.
     * @param array  $files         Optional file uploads keyed by 'field_<id>'.
     * @param bool   $skip_required If true (webhook mode), missing required
     *                              fields don't fail — they're just left empty.
     *                              Set true for API/webhook submissions where
     *                              the sender already decided what to send.
     * @param string $source_type   'public_form' (default, browser submission)
     *                              or 'webhook' (REST API submission). Stored
     *                              on the submission row for reporting.
     */
    public static function process_submission( $slug, $post_data, $files = array(), $skip_required = false, $source_type = 'public_form' ) {
        $form = self::get_by_slug( $slug );
        if ( ! $form || ! $form->enabled ) return new WP_Error( 'not_found', __( 'Form not available', 'cmms-light' ) );

        $account = CMMS_Accounts::get( $form->account_id );
        if ( ! $account || $account->status !== 'active' ) return new WP_Error( 'inactive', __( 'Account inactive', 'cmms-light' ) );

        $fields = self::get_fields( $form->id );

        $answers = array();
        $task_title = '';
        $description_lines = array();

        foreach ( $fields as $field ) {
            $key = 'field_' . $field->id;
            $val = '';
            $field_options = array_filter( array_map( 'trim', explode( ',', (string) $field->options ) ) );

            if ( $field->field_type === 'checkbox' ) {
                if ( ! empty( $field_options ) ) {
                    // Multi-checkbox: $_POST gives us an array of selected
                    // values. Filter to only allowed options (defense
                    // against arbitrary extra values posted) and join with
                    // comma for storage.
                    $selected = isset( $post_data[ $key ] ) && is_array( $post_data[ $key ] )
                        ? $post_data[ $key ]
                        : array();
                    $selected = array_values( array_intersect( $field_options, array_map( 'sanitize_text_field', $selected ) ) );
                    $val = empty( $selected ) ? '' : implode( ', ', $selected );
                } else {
                    // Single yes/no checkbox.
                    $val = isset( $post_data[ $key ] ) ? '1' : '0';
                }
            } elseif ( $field->field_type === 'file' ) {
                $val = isset( $files[ $key ] ) ? $files[ $key ] : null;
            } elseif ( $field->field_type === 'date' ) {
                // Date inputs come back as YYYY-MM-DD or empty.
                $raw = isset( $post_data[ $key ] ) ? trim( (string) $post_data[ $key ] ) : '';
                $val = ( $raw && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) ? $raw : '';
            } else {
                $val = isset( $post_data[ $key ] ) ? sanitize_textarea_field( $post_data[ $key ] ) : '';
            }
            // Required validation. Webhook mode (skip_required=true) deliberately
            // skips this — the external system has already decided what to send,
            // and rejecting incomplete payloads would just cause lost tickets.
            // See process_webhook for the rationale.
            if ( ! $skip_required && $field->required && $field->field_type !== 'file' ) {
                $is_empty = false;
                if ( $field->field_type === 'checkbox' ) {
                    if ( ! empty( $field_options ) ) {
                        $is_empty = ( $val === '' );
                    } else {
                        $is_empty = ( $val !== '1' );
                    }
                } else {
                    $is_empty = ( $val === '' );
                }
                if ( $is_empty ) {
                    return new WP_Error( 'required', sprintf( __( 'Field "%s" is required', 'cmms-light' ), $field->label ) );
                }
            }
            $answers[ $field->id ] = array(
                'label' => $field->label,
                'type'  => $field->field_type,
                'value' => is_array( $val ) ? '' : $val,
            );
            if ( ! is_array( $val ) && $val !== '' ) {
                if ( empty( $task_title ) && $field->field_type === 'text' ) {
                    $task_title = $val;
                }
                if ( $field->field_type === 'checkbox' && empty( $field_options ) ) {
                    // Single yes/no — render as "Yes"/"No"
                    $description_lines[] = $field->label . ': ' . ( $val === '1' ? 'Yes' : 'No' );
                } else {
                    // Everything else (including multi-checkbox joined with commas) prints as-is
                    $description_lines[] = $field->label . ': ' . $val;
                }
            }
        }

        // Title fallback. For webhooks, the caller may pass an explicit
        // 'title' override in post_data (the webhook handler does this for
        // the standard 'title' API field). Honor that before falling back
        // to first text-field value or the generated default.
        if ( ! empty( $post_data['__webhook_title'] ) ) {
            $task_title = sanitize_text_field( $post_data['__webhook_title'] );
        }
        if ( empty( $task_title ) ) {
            // 1.14.94: per the webhook spec, when no title can be derived
            // we use a stable Hebrew default rather than auto-generating
            // one from the form name + timestamp. This keeps webhook-created
            // tasks visually distinct in the task list.
            $task_title = $source_type === 'webhook'
                ? __( 'New task', 'cmms-light' )
                : ( $form->name . ' - ' . current_time( 'Y-m-d H:i' ) );
        }

        // Webhook callers can override the standard task fields by passing
        // them via __webhook_* keys (set by CMMS_Webhook::process). These
        // are not visible to public-form submitters.
        $task_description = implode( "\n", $description_lines );
        if ( isset( $post_data['__webhook_description'] ) && $post_data['__webhook_description'] !== '' ) {
            $task_description = wp_kses_post( $post_data['__webhook_description'] );
            // If both a description AND field answers exist, keep both.
            if ( ! empty( $description_lines ) ) {
                $task_description .= "\n\n" . implode( "\n", $description_lines );
            }
        }
        $task_priority = $form->default_priority;
        if ( ! empty( $post_data['__webhook_priority'] ) ) {
            $task_priority = sanitize_text_field( $post_data['__webhook_priority'] );
        }
        $task_status = $form->default_status;
        if ( ! empty( $post_data['__webhook_status'] ) ) {
            $task_status = sanitize_text_field( $post_data['__webhook_status'] );
        }

        // 1.15.11: Resolve assignee + manager. Start from the form's
        // defaults, then let a webhook override them per-request. Any
        // overriding user id must belong to the same account — we never
        // trust a raw id from the request.
        $task_manager  = ! empty( $form->manager_id ) ? (int) $form->manager_id : null;
        $task_assignee = ! empty( $form->default_assignee_id ) ? (int) $form->default_assignee_id : null;

        if ( ! empty( $post_data['__webhook_manager_id'] ) ) {
            $cand = (int) $post_data['__webhook_manager_id'];
            $cu = CMMS_Users::get( $cand );
            if ( $cu && (int) $cu->account_id === (int) $form->account_id ) {
                $task_manager = $cand;
            }
        }
        if ( ! empty( $post_data['__webhook_assignee_id'] ) ) {
            $cand = (int) $post_data['__webhook_assignee_id'];
            $cu = CMMS_Users::get( $cand );
            if ( $cu && (int) $cu->account_id === (int) $form->account_id ) {
                $task_assignee = $cand;
            }
        }

        $task_id = CMMS_Tasks::create( $form->account_id, array(
            'title'        => $task_title,
            'description'  => $task_description,
            'category_id'  => $form->default_category_id,
            'manager_id'   => $task_manager,
            // 1.15.11: default assignee ("מוקצה ל") — the technician who
            // performs the work. Form default, overridable via webhook.
            'assigned_to'  => $task_assignee,
            'priority'     => $task_priority,
            'status'       => $task_status,
            'source'       => $source_type === 'webhook' ? 'Webhook API' : 'External Form',
            'external_data'=> $answers,
        ) );

        if ( is_wp_error( $task_id ) ) return $task_id;

        global $wpdb;
        $wpdb->insert( CMMS_DB::table( 'form_submissions' ), array(
            'form_id'     => $form->id,
            'account_id'  => $form->account_id,
            'task_id'     => $task_id,
            'data'        => wp_json_encode( $answers ),
            'ip_address'  => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '',
            'source_type' => $source_type,
            'created_at'  => current_time( 'mysql' ),
        ), array( '%d','%d','%d','%s','%s','%s','%s' ) );

        // Handle file uploads
        if ( ! empty( $files ) ) {
            foreach ( $files as $key => $file ) {
                if ( strpos( $key, 'field_' ) !== 0 ) continue;
                if ( empty( $file['name'] ) ) continue;
                CMMS_Attachments::handle_upload( $form->account_id, 'task', $task_id, $file, null );
            }
        }

        do_action( 'cmms_public_submitted', (int) $task_id, (int) $form->id );

        return $task_id;
    }
}
