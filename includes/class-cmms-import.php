<?php
/**
 * CMMS Import — Excel-based bulk import for ASSETS only.
 *
 * Tasks are intentionally NOT supported here. The reasoning:
 *  - Tasks require dropdown lookups (asset, assignee) that are inherently
 *    interactive — Excel forces the user to type names that may not match
 *    exactly, which produces validation pain.
 *  - The companion Task Grid screen (in-app paste-from-Excel UI) handles
 *    bulk task entry better, with live dropdowns and inline validation.
 *
 * So the workflow is:
 *  1. Customer uploads Assets here.
 *  2. Customer goes to Task Grid and pastes from Excel for tasks.
 *
 * Design choices:
 *  - SimpleXLSX bundled (no Composer, ~one PHP file).
 *  - Hard caps: 1000 rows, 5MB. Beyond that, server timeouts become real.
 *  - Validation is permissive: bad rows are SKIPPED, not fatal. The user
 *    gets a summary + downloadable error CSV.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Import {

    const MAX_ROWS       = 1000;
    const MAX_FILE_BYTES = 5 * 1024 * 1024; // 5 MB

    /* ===================================================================
     * Template generation — single sheet (Assets), 6 columns + example row.
     * ===================================================================*/

    public static function generate_template() {
        if ( ! class_exists( 'ZipArchive' ) ) return false;

        $tmp = wp_tempnam( 'cmms-assets-template-' );
        if ( ! $tmp ) return false;

        $zip = new ZipArchive();
        if ( $zip->open( $tmp, ZipArchive::OVERWRITE ) !== true ) {
            return false;
        }

        $zip->addFromString( '[Content_Types].xml',          self::tpl_content_types() );
        $zip->addFromString( '_rels/.rels',                  self::tpl_root_rels() );
        $zip->addFromString( 'xl/workbook.xml',              self::tpl_workbook() );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels',   self::tpl_workbook_rels() );
        $zip->addFromString( 'xl/sharedStrings.xml',         self::tpl_shared_strings() );
        $zip->addFromString( 'xl/styles.xml',                self::tpl_styles() );
        $zip->addFromString( 'xl/worksheets/sheet1.xml',     self::tpl_assets_sheet() );

        $zip->close();
        return $tmp;
    }

    private static function tpl_content_types() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function tpl_root_rels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function tpl_workbook() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>'
            . '<sheet name="Assets" sheetId="1" r:id="rId1"/>'
            . '</sheets>'
            . '</workbook>';
    }

    private static function tpl_workbook_rels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function tpl_shared_strings() {
        $strings = array(
            // Headers (0..5)
            'asset_name', 'location', 'model', 'manufacturer', 'install_date', 'notes',
            // Example row 1 (6..11)
            'Air Conditioner #1', 'Building A, Floor 2', 'AC-2000', 'CoolCo', '2024-01-15', 'Annual service required',
            // Example row 2 (12..17)
            'Generator #2', 'Generator Room', 'GEN-5000', 'PowerCo', '2023-06-20', '',
        );
        $count = count( $strings );
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">';
        foreach ( $strings as $s ) {
            $xml .= '<si><t xml:space="preserve">' . htmlspecialchars( $s, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }

    private static function tpl_styles() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellXfs count="2">'
            . '<xf fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    private static function tpl_assets_sheet() {
        // Row 1: headers (indices 0..5). Row 2 + 3: example rows.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>'
            . '<row r="1">'
            . '<c r="A1" t="s" s="1"><v>0</v></c>'
            . '<c r="B1" t="s" s="1"><v>1</v></c>'
            . '<c r="C1" t="s" s="1"><v>2</v></c>'
            . '<c r="D1" t="s" s="1"><v>3</v></c>'
            . '<c r="E1" t="s" s="1"><v>4</v></c>'
            . '<c r="F1" t="s" s="1"><v>5</v></c>'
            . '</row>'
            . '<row r="2">'
            . '<c r="A2" t="s"><v>6</v></c>'
            . '<c r="B2" t="s"><v>7</v></c>'
            . '<c r="C2" t="s"><v>8</v></c>'
            . '<c r="D2" t="s"><v>9</v></c>'
            . '<c r="E2" t="s"><v>10</v></c>'
            . '<c r="F2" t="s"><v>11</v></c>'
            . '</row>'
            . '<row r="3">'
            . '<c r="A3" t="s"><v>12</v></c>'
            . '<c r="B3" t="s"><v>13</v></c>'
            . '<c r="C3" t="s"><v>14</v></c>'
            . '<c r="D3" t="s"><v>15</v></c>'
            . '<c r="E3" t="s"><v>16</v></c>'
            . '<c r="F3" t="s"><v>17</v></c>'
            . '</row>'
            . '</sheetData>'
            . '</worksheet>';
    }

    /* ===================================================================
     * Import (assets only)
     * ===================================================================*/

    /**
     * @return array {
     *   imported_assets:int,
     *   skipped_rows:array<{row:int, reason:string}>,
     *   error?:string
     * }
     */
    public static function import_file( $file_path, $account_id, $user ) {
        $result = array(
            'imported_assets' => 0,
            'skipped_rows'    => array(),
        );

        if ( ! class_exists( 'CMMS_SimpleXLSX' ) ) {
            $candidate = CMMS_LIGHT_DIR . 'vendor/simplexlsx/SimpleXLSX.php';
            if ( file_exists( $candidate ) ) require_once $candidate;
        }
        if ( ! class_exists( 'CMMS_SimpleXLSX' ) ) {
            $result['error'] = 'XLSX reader unavailable';
            return $result;
        }

        $xlsx = CMMS_SimpleXLSX::parse( $file_path );
        if ( ! $xlsx ) {
            $result['error'] = 'Could not parse the file. Make sure it is a real .xlsx file.';
            return $result;
        }

        // Default to first sheet — handles "Assets", "נכסים", "Sheet1", or whatever.
        $sheet_names = $xlsx->sheet_names();
        $rows = $sheet_names ? $xlsx->rows( $sheet_names[0] ) : array();

        // Normalize-name lookup map of existing assets so we don't insert duplicates.
        $existing_map = self::existing_asset_map( $account_id );

        $row_index = 0;
        foreach ( $rows as $row ) {
            $row_index++;
            if ( $row_index === 1 ) continue; // header
            if ( $row_index > self::MAX_ROWS + 1 ) break;
            if ( self::is_blank_row( $row ) ) continue;

            $name = self::cell( $row, 0 );
            if ( $name === '' ) {
                $result['skipped_rows'][] = array( 'row' => $row_index, 'reason' => 'asset_name is required' );
                continue;
            }
            $key = self::normalize_name( $name );
            if ( isset( $existing_map[ $key ] ) ) {
                $result['skipped_rows'][] = array( 'row' => $row_index, 'reason' => 'asset already exists with this name' );
                continue;
            }

            $location     = self::cell( $row, 1 );
            $model        = self::cell( $row, 2 );
            $manufacturer = self::cell( $row, 3 );
            $install_date = self::parse_date( self::cell( $row, 4 ) );
            $notes        = self::cell( $row, 5 );

            $desc_parts = array();
            if ( $model )        $desc_parts[] = 'Model: ' . $model;
            if ( $manufacturer ) $desc_parts[] = 'Manufacturer: ' . $manufacturer;
            if ( $install_date ) $desc_parts[] = 'Installed: ' . $install_date;
            if ( $notes )        $desc_parts[] = $notes;
            $description = implode( "\n", $desc_parts );

            $new_id = CMMS_Assets::create( $account_id, array(
                'name'        => $name,
                'asset_type'  => 'machine',
                'location'    => $location,
                'description' => $description,
                'created_by'  => isset( $user->id ) ? (int) $user->id : null,
            ) );

            if ( is_wp_error( $new_id ) || ! $new_id ) {
                $msg = is_wp_error( $new_id ) ? $new_id->get_error_message() : 'database insert failed';
                $result['skipped_rows'][] = array( 'row' => $row_index, 'reason' => $msg );
                continue;
            }
            $existing_map[ $key ] = (int) $new_id;
            $result['imported_assets']++;
        }

        return $result;
    }

    /* ===================================================================
     * Helpers
     * ===================================================================*/

    private static function cell( $row, $idx ) {
        if ( ! isset( $row[ $idx ] ) ) return '';
        return trim( (string) $row[ $idx ] );
    }

    private static function is_blank_row( $row ) {
        if ( empty( $row ) ) return true;
        foreach ( $row as $cell ) {
            if ( trim( (string) $cell ) !== '' ) return false;
        }
        return true;
    }

    /**
     * Normalize a name for duplicate-detection. Folds away common typo
     * differences:
     *  - Leading / trailing whitespace
     *  - Multiple spaces collapsed to one
     *  - Curly quotes folded to straight
     *  - Case-insensitive
     */
    public static function normalize_name( $raw ) {
        $s = (string) $raw;
        // Curly quotes → straight
        $s = str_replace( array( "\xE2\x80\x99", "\xE2\x80\x98", "\xE2\x80\x9C", "\xE2\x80\x9D" ),
                          array( "'", "'", '"', '"' ), $s );
        // Collapse whitespace runs
        $s = preg_replace( '/\s+/u', ' ', $s );
        $s = trim( $s );
        return mb_strtolower( $s );
    }

    private static function parse_date( $raw ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) return '';

        // Excel serial number?
        if ( is_numeric( $raw ) && (float) $raw > 1 && (float) $raw < 100000 ) {
            $unix = ( (float) $raw - 25569 ) * 86400;
            if ( $unix > 0 ) return gmdate( 'Y-m-d H:i:s', (int) $unix );
        }

        // ISO / strtotime
        $ts = strtotime( $raw );
        if ( $ts !== false ) return date( 'Y-m-d H:i:s', $ts );

        // DD/MM/YYYY (Israeli)
        if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $raw, $m ) ) {
            $year  = (int) $m[3]; if ( $year < 100 ) $year += 2000;
            $month = (int) $m[2];
            $day   = (int) $m[1];
            if ( checkdate( $month, $day, $year ) ) {
                return sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $day );
            }
        }

        return '';
    }

    private static function existing_asset_map( $account_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'assets' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM $table WHERE account_id = %d",
            (int) $account_id
        ) );
        $map = array();
        foreach ( (array) $rows as $r ) {
            $map[ self::normalize_name( $r->name ) ] = (int) $r->id;
        }
        return $map;
    }

    public static function build_error_csv( $skipped_rows ) {
        $lines = array();
        $lines[] = 'row,reason';
        foreach ( $skipped_rows as $sk ) {
            $lines[] = sprintf( '%d,"%s"',
                (int) $sk['row'],
                str_replace( '"', '""', $sk['reason'] )
            );
        }
        return "\xEF\xBB\xBF" . implode( "\r\n", $lines );
    }

    public static function is_real_xlsx( $file_path ) {
        if ( ! is_readable( $file_path ) ) return false;
        $f = fopen( $file_path, 'rb' );
        if ( ! $f ) return false;
        $head = fread( $f, 4 );
        fclose( $f );
        return $head !== false && substr( $head, 0, 2 ) === 'PK';
    }
}
