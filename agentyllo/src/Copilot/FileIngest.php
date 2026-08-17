<?php
/**
 * File ingestion (TXT / Markdown / CSV) into manual KB entries.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Copilot;

use Agentyllo\Compliance\Audit;
use Agentyllo\KB\ManualEntries;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Upload → parse → PREVIEW rows (title, content, type) the owner reviews and
 * edits in the drawer → commit. Native parsers only in this build: plain
 * text / Markdown (split on headings or blank-line paragraphs, ≤ 4k chars per
 * entry) and CSV (first row = header; the first text column becomes the
 * title, the longest the content — or explicit title/content/question/answer
 * columns). PDF/XLSX arrive with the scoped-vendor build (smalot/pdfparser
 * with the hardening described in the plan, OpenSpout) — this class already
 * exposes the extension point (`agy_ingest_parsers`). Limits: 3 MB, 500 rows.
 */
final class FileIngest {

	private const MAX_BYTES = 3 * 1024 * 1024;
	private const MAX_ROWS  = 500;
	private const MAX_ENTRY = 4000;

	/**
	 * Constructor.
	 *
	 * @param ManualEntries $entries Manual entries writer.
	 */
	public function __construct( private readonly ManualEntries $entries ) {
	}

	/**
	 * Parse an uploaded file into preview rows.
	 *
	 * @param string $tmp_path Temp path.
	 * @param string $name     Original name.
	 * @param int    $size     Size in bytes.
	 * @return array{filename: string, kind: string, rows: array<int, array{title: string, content: string, type: string}>}|WP_Error
	 */
	public function parse_upload( string $tmp_path, string $name, int $size ): array|WP_Error {
		if ( $size > self::MAX_BYTES || filesize( $tmp_path ) > self::MAX_BYTES ) {
			return new WP_Error( 'agy_file_too_large', __( 'File too large (max 3 MB).', 'agentyllo' ), array( 'status' => 413 ) );
		}
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		/**
		 * Filter the parsers by extension: ext => callable(string $path): array<int, array{title, content, type}>|WP_Error.
		 *
		 * @param array<string, callable> $parsers Parsers.
		 */
		$parsers = (array) apply_filters(
			'agy_ingest_parsers',
			array(
				'txt' => array( $this, 'parse_text' ),
				'md'  => array( $this, 'parse_text' ),
				'csv' => array( $this, 'parse_csv' ),
			)
		);
		if ( ! isset( $parsers[ $ext ] ) || ! is_callable( $parsers[ $ext ] ) ) {
			return new WP_Error(
				'agy_unsupported_file',
				sprintf(
					/* translators: %s: comma-separated extensions. */
					__( 'Unsupported file type. Supported: %s. PDF and spreadsheets are supported by the extended build; you can also paste text directly.', 'agentyllo' ),
					implode( ', ', array_keys( $parsers ) )
				),
				array( 'status' => 415 )
			);
		}

		$rows = ( $parsers[ $ext ] )( $tmp_path );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$rows = array_slice( array_values( array_filter( $rows, static fn ( $r ): bool => is_array( $r ) && '' !== trim( (string) ( $r['content'] ?? '' ) ) ) ), 0, self::MAX_ROWS );
		if ( ! $rows ) {
			return new WP_Error( 'agy_empty_file', __( 'No usable text found in the file.', 'agentyllo' ), array( 'status' => 422 ) );
		}

		return array(
			'filename' => sanitize_file_name( $name ),
			'kind'     => $ext,
			'rows'     => $rows,
		);
	}

	/**
	 * Commit reviewed rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows (title, content, type, include).
	 * @return array{created: int, skipped: int, ids: int[]}
	 */
	public function commit( array $rows ): array {
		$created = 0;
		$skipped = 0;
		$ids     = array();
		foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $row ) {
			if ( ! is_array( $row ) || ( array_key_exists( 'include', $row ) && ! rest_sanitize_boolean( $row['include'] ) ) ) {
				++$skipped;
				continue;
			}
			$title   = trim( sanitize_text_field( (string) ( $row['title'] ?? '' ) ) );
			$content = trim( sanitize_textarea_field( (string) ( $row['content'] ?? '' ) ) );
			$type    = in_array( (string) ( $row['type'] ?? '' ), array( 'note', 'faq' ), true ) ? (string) $row['type'] : 'file';
			if ( '' === $content ) {
				++$skipped;
				continue;
			}
			if ( '' === $title ) {
				$title = mb_substr( $content, 0, 60 );
			}
			$id = $this->entries->create( $title, mb_substr( $content, 0, 20000 ), $type );
			if ( $id > 0 ) {
				++$created;
				$ids[] = $id;
			} else {
				++$skipped;
			}
		}
		Audit::log( 'copilot.ingest', null, array( 'rows' => count( $rows ) ), 'ok', sprintf( 'created=%d skipped=%d', $created, $skipped ) );

		return array(
			'created' => $created,
			'skipped' => $skipped,
			'ids'     => $ids,
		);
	}

	/**
	 * Plain text / Markdown → sections.
	 *
	 * @param string $path File path.
	 * @return array<int, array{title: string, content: string, type: string}>
	 */
	public function parse_text( string $path ): array {
		$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$text = str_replace( "\r\n", "\n", wp_strip_all_tags( $text ) );
		$text = (string) preg_replace( '/[^\P{C}\n\t]/u', '', $text );

		$rows = array();
		// Heading-driven sections when the file uses Markdown headings.
		if ( preg_match_all( '/^(#{1,3})\s+(.+)$/mu', $text ) >= 2 ) {
			$parts = preg_split( '/^(?=#{1,3}\s+)/mu', $text ) ?: array();
			foreach ( $parts as $part ) {
				$part = trim( $part );
				if ( '' === $part ) {
					continue;
				}
				$title = '';
				if ( preg_match( '/^#{1,3}\s+(.+)$/mu', $part, $m ) ) {
					$title = trim( $m[1] );
					$part  = trim( (string) preg_replace( '/^#{1,3}\s+.+$/mu', '', $part, 1 ) );
				}
				foreach ( $this->split_long( $part ) as $i => $chunk ) {
					$rows[] = array(
						'title'   => '' !== $title ? $title . ( $i > 0 ? ' (' . ( $i + 1 ) . ')' : '' ) : mb_substr( $chunk, 0, 60 ),
						'content' => $chunk,
						'type'    => 'file',
					);
				}
			}

			return $rows;
		}

		// Otherwise: one entry per ~MAX_ENTRY chars, split on blank lines.
		foreach ( $this->split_long( trim( $text ) ) as $i => $chunk ) {
			$first  = trim( (string) strtok( $chunk, "\n" ) );
			$rows[] = array(
				'title'   => mb_substr( '' !== $first ? $first : $chunk, 0, 60 ) . ( $i > 0 ? ' (' . ( $i + 1 ) . ')' : '' ),
				'content' => $chunk,
				'type'    => 'file',
			);
		}

		return $rows;
	}

	/**
	 * CSV → rows (title/content or question/answer columns; else first text
	 * column = title, longest column = content).
	 *
	 * @param string $path File path.
	 * @return array<int, array{title: string, content: string, type: string}>|WP_Error
	 */
	public function parse_csv( string $path ): array|WP_Error {
		$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return new WP_Error( 'agy_read_failed', __( 'Could not read the file.', 'agentyllo' ), array( 'status' => 500 ) );
		}
		$header = fgetcsv( $fh, 0, ',', '"', '\\' );
		if ( ! is_array( $header ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			return array();
		}
		$header  = array_map( static fn ( $h ): string => strtolower( trim( (string) $h ) ), $header );
		$t_idx   = self::find_col( $header, array( 'title', 'question', 'q', 'domanda', 'titolo', 'name', 'nome' ) );
		$c_idx   = self::find_col( $header, array( 'content', 'answer', 'a', 'risposta', 'contenuto', 'text', 'testo', 'description', 'descrizione', 'body' ) );
		$is_faq  = null !== self::find_col( $header, array( 'question', 'q', 'domanda' ) );
		$rows    = array();
		$counter = 0;
		while ( ( $line = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) {
			if ( ++$counter > self::MAX_ROWS ) {
				break;
			}
			$line = array_map( static fn ( $v ): string => trim( (string) $v ), $line );
			if ( '' === implode( '', $line ) ) {
				continue;
			}
			if ( null !== $t_idx && null !== $c_idx ) {
				$title   = (string) ( $line[ $t_idx ] ?? '' );
				$content = (string) ( $line[ $c_idx ] ?? '' );
			} else {
				$longest = 0;
				$content = '';
				$title   = '';
				foreach ( $line as $i => $value ) {
					if ( '' === $title && '' !== $value && ! is_numeric( $value ) ) {
						$title = $value;
					}
					if ( mb_strlen( $value ) > $longest ) {
						$longest = mb_strlen( $value );
						$content = $value;
					}
				}
				// Row context: "Header: value" pairs make spreadsheets answerable.
				$pairs = array();
				foreach ( $line as $i => $value ) {
					if ( '' !== $value && '' !== ( $header[ $i ] ?? '' ) ) {
						$pairs[] = ucfirst( (string) $header[ $i ] ) . ': ' . $value;
					}
				}
				$content = implode( "\n", $pairs ) ?: $content;
			}
			if ( '' === trim( $content ) ) {
				continue;
			}
			$rows[] = array(
				'title'   => mb_substr( '' !== $title ? $title : $content, 0, 120 ),
				'content' => $is_faq && null !== $t_idx ? 'Q: ' . $title . "\nA: " . $content : mb_substr( $content, 0, self::MAX_ENTRY ),
				'type'    => $is_faq ? 'faq' : 'file',
			);
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $rows;
	}

	/**
	 * Column index by candidate names.
	 *
	 * @param string[] $header     Header.
	 * @param string[] $candidates Names.
	 */
	private static function find_col( array $header, array $candidates ): ?int {
		foreach ( $candidates as $name ) {
			$idx = array_search( $name, $header, true );
			if ( false !== $idx ) {
				return (int) $idx;
			}
		}

		return null;
	}

	/**
	 * Split long text on paragraph boundaries into ≤ MAX_ENTRY pieces.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private function split_long( string $text ): array {
		if ( '' === $text ) {
			return array();
		}
		if ( mb_strlen( $text ) <= self::MAX_ENTRY ) {
			return array( $text );
		}
		$out    = array();
		$buffer = '';
		foreach ( preg_split( '/\n{2,}/u', $text ) ?: array( $text ) as $para ) {
			if ( mb_strlen( $buffer . "\n\n" . $para ) > self::MAX_ENTRY && '' !== $buffer ) {
				$out[]  = trim( $buffer );
				$buffer = '';
			}
			$buffer .= ( '' === $buffer ? '' : "\n\n" ) . $para;
		}
		if ( '' !== trim( $buffer ) ) {
			$out[] = trim( $buffer );
		}

		return $out;
	}
}
