<?php
/**
 * SimpleXLSX — minimal XLSX reader.
 * Bundled here (~one file) instead of pulling Composer's PhpSpreadsheet
 * which would add ~5MB of dependencies for what is essentially "open zip,
 * read XML, give me cells".
 *
 * Origin: distilled from public-domain exam ple code by Sergey Shuchkin.
 * Reduced to only the read-rows feature we need; no styles, no images,
 * no formulas, no write support.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_SimpleXLSX {

    private $sheets = array();        // sheet name => array of rows
    private $shared_strings = array();
    private $error = '';

    public static function parse( $file_path ) {
        $instance = new self();
        if ( ! $instance->load( $file_path ) ) {
            return false;
        }
        return $instance;
    }

    public function get_error() { return $this->error; }

    public function sheet_names() { return array_keys( $this->sheets ); }

    /** Returns array of rows (each row = array of cell values, indexed numerically). */
    public function rows( $sheet_name = null ) {
        if ( $sheet_name === null ) {
            $names = array_keys( $this->sheets );
            return $names ? $this->sheets[ $names[0] ] : array();
        }
        // case-insensitive sheet lookup
        foreach ( $this->sheets as $name => $rows ) {
            if ( strcasecmp( $name, $sheet_name ) === 0 ) return $rows;
        }
        return array();
    }

    private function load( $file_path ) {
        if ( ! is_readable( $file_path ) ) {
            $this->error = 'File not readable';
            return false;
        }
        if ( ! class_exists( 'ZipArchive' ) ) {
            $this->error = 'ZipArchive extension required';
            return false;
        }
        $zip = new ZipArchive();
        if ( $zip->open( $file_path ) !== true ) {
            $this->error = 'Could not open xlsx file';
            return false;
        }

        // 1. Read shared strings table (where text cells store their values).
        $shared = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( $shared !== false ) {
            $this->parse_shared_strings( $shared );
        }

        // 2. Read workbook.xml to map sheet names -> sheet IDs / file paths.
        $workbook_xml = $zip->getFromName( 'xl/workbook.xml' );
        $rels_xml     = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
        if ( $workbook_xml === false || $rels_xml === false ) {
            $zip->close();
            $this->error = 'Invalid xlsx: missing workbook';
            return false;
        }

        $sheet_map = $this->build_sheet_map( $workbook_xml, $rels_xml );

        // 3. Read each sheet's XML.
        foreach ( $sheet_map as $sheet_name => $target ) {
            // Targets in rels are relative to xl/, e.g. "worksheets/sheet1.xml"
            $path = strpos( $target, '/' ) === 0 ? ltrim( $target, '/' ) : 'xl/' . $target;
            $xml = $zip->getFromName( $path );
            if ( $xml === false ) continue;
            $this->sheets[ $sheet_name ] = $this->parse_sheet( $xml );
        }
        $zip->close();
        return true;
    }

    private function parse_shared_strings( $xml_text ) {
        // <si><t>value</t></si> or <si><r>...rich...</r></si>
        $doc = @simplexml_load_string( $xml_text );
        if ( ! $doc ) return;
        foreach ( $doc->si as $si ) {
            // Concatenate all <t> text nodes (handles rich text too)
            $value = '';
            foreach ( $si->xpath( './/*[local-name()="t"]' ) as $t ) {
                $value .= (string) $t;
            }
            $this->shared_strings[] = $value;
        }
    }

    private function build_sheet_map( $workbook_xml, $rels_xml ) {
        $map = array(); // sheet_name => target file path

        $rels = @simplexml_load_string( $rels_xml );
        if ( ! $rels ) return $map;
        $rel_targets = array(); // rId => target
        foreach ( $rels->Relationship as $rel ) {
            $rid = (string) $rel['Id'];
            $rel_targets[ $rid ] = (string) $rel['Target'];
        }

        $wb = @simplexml_load_string( $workbook_xml );
        if ( ! $wb ) return $map;
        // Sheet names + their relationship IDs
        if ( isset( $wb->sheets ) ) {
            foreach ( $wb->sheets->sheet as $sheet ) {
                $name = (string) $sheet['name'];
                // r:id is in the relationships namespace
                $rid = '';
                foreach ( $sheet->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' ) as $k => $v ) {
                    if ( $k === 'id' ) $rid = (string) $v;
                }
                if ( $rid && isset( $rel_targets[ $rid ] ) ) {
                    $map[ $name ] = $rel_targets[ $rid ];
                }
            }
        }
        return $map;
    }

    private function parse_sheet( $xml_text ) {
        $rows = array();
        $doc = @simplexml_load_string( $xml_text );
        if ( ! $doc ) return $rows;

        foreach ( $doc->sheetData->row as $row ) {
            $row_index = (int) $row['r']; // 1-based
            $row_data = array();
            foreach ( $row->c as $c ) {
                $ref  = (string) $c['r']; // e.g. "B5"
                $type = (string) $c['t']; // 's' = shared string, 'b' = bool, '' = number, 'inlineStr' = inline
                $col_index = $this->col_letter_to_index( preg_replace( '/[^A-Z]/', '', $ref ) );
                $value = '';
                if ( $type === 's' ) {
                    $idx = (int) $c->v;
                    if ( isset( $this->shared_strings[ $idx ] ) ) {
                        $value = $this->shared_strings[ $idx ];
                    }
                } elseif ( $type === 'inlineStr' ) {
                    foreach ( $c->xpath( './/*[local-name()="t"]' ) as $t ) {
                        $value .= (string) $t;
                    }
                } elseif ( $type === 'b' ) {
                    $value = ((int) $c->v) ? 'TRUE' : 'FALSE';
                } else {
                    $value = (string) $c->v;
                }
                $row_data[ $col_index ] = $value;
            }
            // Pad to a uniform width per row to keep column indexes aligned
            if ( $row_data ) {
                $max_col = max( array_keys( $row_data ) );
                $padded = array();
                for ( $i = 0; $i <= $max_col; $i++ ) {
                    $padded[ $i ] = isset( $row_data[ $i ] ) ? $row_data[ $i ] : '';
                }
                $rows[] = $padded;
            } else {
                $rows[] = array(); // blank row
            }
        }
        return $rows;
    }

    /** Convert "A" -> 0, "B" -> 1, "AA" -> 26, etc. */
    private function col_letter_to_index( $letters ) {
        $n = 0;
        $len = strlen( $letters );
        for ( $i = 0; $i < $len; $i++ ) {
            $n = $n * 26 + ( ord( $letters[ $i ] ) - 64 );
        }
        return max( 0, $n - 1 );
    }
}
