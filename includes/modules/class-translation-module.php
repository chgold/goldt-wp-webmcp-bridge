<?php
/**
 * Translation Module
 *
 * Provides translation tools via MyMemory free API.
 * Only active when the translation provider is set to 'mymemory'.
 *
 * @package AIConnect
 * @since 0.3.0
 */

namespace GoldtWebMCP\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation module providing translate and getSupportedLanguages tools.
 *
 * @package GoldtWebMCP
 */
class Translation_Module extends Module_Base {

	/**
	 * Module name identifier.
	 *
	 * @var string
	 */
	protected $module_name = 'translation';

	const MYMEMORY_API = 'https://api.mymemory.translated.net/get';

	/**
	 * Register tools — only when MyMemory provider is selected.
	 */
	protected function register_tools() {
		$provider = \get_option( 'goldtwmcp_translation_provider', 'ai_self' );
		if ( 'mymemory' !== $provider ) {
			return;
		}

		$this->register_tool(
			'translate',
			array(
				'description'    => 'Translate text between languages. Supports text of any length (automatically split into chunks if needed). Source language is auto-detected if not specified.',
				'required_scope' => 'read',
				'input_schema'   => array(
					'type'       => 'object',
					'required'   => array( 'text', 'target_lang' ),
					'properties' => array(
						'text'        => array(
							'type'        => 'string',
							'description' => 'Text to translate',
						),
						'source_lang' => array(
							'type'        => 'string',
							'description' => 'Source language code (e.g., "en", "he", "es"). Leave empty for auto-detection.',
						),
						'target_lang' => array(
							'type'        => 'string',
							'description' => 'Target language code (e.g., "en", "he", "es", "fr", "de", "ru")',
						),
					),
				),
			)
		);

		$this->register_tool(
			'getSupportedLanguages',
			array(
				'description'    => 'Get list of commonly supported language codes for translation',
				'required_scope' => 'read',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(),
				),
			)
		);
	}

	/**
	 * Execute the translate tool.
	 *
	 * @param array $params Tool parameters.
	 * @return array|\WP_Error
	 */
	public function execute_translate( $params ) {
		$text        = $params['text'];
		$source_lang = $params['source_lang'] ?? '';
		$target_lang = $params['target_lang'];

		try {
			$chunks     = $this->chunk_text( $text );
			$translated = array();

			foreach ( $chunks as $chunk ) {
				$result = $this->translate_chunk( $chunk, $source_lang, $target_lang );
				if ( ! $result['success'] ) {
					return $this->error_response( 'Translation failed: ' . $result['error'], 'translation_failed' );
				}
				$translated[] = $result['text'];
			}

			$translated_text = implode( ' ', $translated );

			return $this->success_response(
				array(
					'original_text'   => $text,
					'translated_text' => $translated_text,
					'source_lang'     => $source_lang ? $source_lang : 'auto',
					'target_lang'     => $target_lang,
					'chunks'          => count( $chunks ),
				)
			);

		} catch ( \Exception $e ) {
			return $this->error_response( 'Translation error: ' . $e->getMessage(), 'exception' );
		}
	}

	/**
	 * Execute the getSupportedLanguages tool.
	 *
	 * @param array $params Tool parameters (unused).
	 * @return array
	 */
	public function execute_getSupportedLanguages( $params ) {
		$languages = array(
			'en' => 'English',
			'he' => 'Hebrew',
			'ar' => 'Arabic',
			'es' => 'Spanish',
			'fr' => 'French',
			'de' => 'German',
			'it' => 'Italian',
			'pt' => 'Portuguese',
			'ru' => 'Russian',
			'zh' => 'Chinese (Simplified)',
			'ja' => 'Japanese',
			'ko' => 'Korean',
			'nl' => 'Dutch',
			'pl' => 'Polish',
			'tr' => 'Turkish',
			'sv' => 'Swedish',
			'da' => 'Danish',
			'no' => 'Norwegian',
			'fi' => 'Finnish',
			'cs' => 'Czech',
			'ro' => 'Romanian',
			'hu' => 'Hungarian',
			'el' => 'Greek',
			'th' => 'Thai',
			'hi' => 'Hindi',
			'id' => 'Indonesian',
			'vi' => 'Vietnamese',
			'uk' => 'Ukrainian',
			'bg' => 'Bulgarian',
			'hr' => 'Croatian',
		);

		return $this->success_response(
			array(
				'languages' => $languages,
				'usage'     => 'Use the language code (e.g., "en", "he") in the translate tool',
			)
		);
	}

	/**
	 * Split text into chunks that fit within the API limit.
	 *
	 * @param string $text      Text to chunk.
	 * @param int    $max_length Maximum bytes per chunk.
	 * @return array
	 */
	protected function chunk_text( $text, $max_length = 450 ) {
		if ( mb_strlen( $text ) <= $max_length ) {
			return array( $text );
		}

		$chunks = array();
		while ( mb_strlen( $text ) > $max_length ) {
			$slice    = mb_substr( $text, 0, $max_length );
			$break_at = null;

			// Find best break point: paragraph > sentence > word.
			foreach ( array( "\n\n", "\n", '. ', '! ', '? ', '; ', ', ' ) as $sep ) {
				$pos = mb_strrpos( $slice, $sep );
				if ( false !== $pos && $pos > (int) ( $max_length / 3 ) ) {
					$break_at = $pos + mb_strlen( $sep );
					break;
				}
			}

			// Fall back to word boundary.
			if ( null === $break_at ) {
				$pos      = mb_strrpos( $slice, ' ' );
				$break_at = ( false !== $pos && $pos > (int) ( $max_length / 3 ) ) ? $pos + 1 : $max_length;
			}

			$chunks[] = rtrim( mb_substr( $text, 0, $break_at ) );
			$text     = ltrim( mb_substr( $text, $break_at ) );
		}

		if ( mb_strlen( $text ) > 0 ) {
			$chunks[] = $text;
		}

		return array_values( array_filter( $chunks ) );
	}

	/**
	 * Translate a single chunk via MyMemory API.
	 *
	 * @param string $text        Text to translate.
	 * @param string $source_lang Source language code (or '' for auto-detect).
	 * @param string $target_lang Target language code.
	 * @return array {success: bool, text?: string, error?: string}
	 */
	protected function translate_chunk( $text, $source_lang, $target_lang ) {
		if ( empty( $source_lang ) || strtolower( $source_lang ) === 'auto' ) {
			$source_lang = $this->detect_language( $text );
		}

		$lang_pair = $source_lang . '|' . $target_lang;

		$url = self::MYMEMORY_API . '?' . http_build_query(
			array(
				'q'        => $text,
				'langpair' => $lang_pair,
			)
		);

		$response = \wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'User-Agent' => 'WordPress-AIConnect/1.0' ),
			)
		);

		if ( \is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$status_code = \wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return array(
				'success' => false,
				'error'   => 'HTTP ' . $status_code,
			);
		}

		$body = \wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! $data || ! isset( $data['responseData']['translatedText'] ) ) {
			return array(
				'success' => false,
				'error'   => $data['responseDetails'] ?? 'Invalid response',
			);
		}

		if ( ! empty( $data['quotaFinished'] ) ) {
			return array(
				'success' => false,
				'error'   => 'quota_exceeded',
				'message' => 'Daily translation quota exceeded (MyMemory free API limit: ~5,000 chars/day). Try again tomorrow.',
			);
		}

		$translated = $data['responseData']['translatedText'];

		// If TM returned the same text, look for a better match in the matches array.
		if ( $this->is_same_text( $text, $translated ) && ! empty( $data['matches'] ) ) {
			foreach ( $data['matches'] as $match ) {
				if ( ! $this->is_same_text( $text, $match['translation'] ) ) {
					$translated = $match['translation'];
					break;
				}
			}
		}

		return array(
			'success' => true,
			'text'    => $translated,
		);
	}

	/**
	 * Detect source language from text using Unicode character ranges.
	 *
	 * @param string $text Input text.
	 * @return string Language code.
	 */
	protected function detect_language( $text ) {
		// Hebrew.
		if ( preg_match( '/[\x{0590}-\x{05FF}]/u', $text ) ) {
			return 'he';
		}
		// Arabic.
		if ( preg_match( '/[\x{0600}-\x{06FF}]/u', $text ) ) {
			return 'ar';
		}
		// Russian/Cyrillic.
		if ( preg_match( '/[\x{0400}-\x{04FF}]/u', $text ) ) {
			return 'ru';
		}
		// Chinese.
		if ( preg_match( '/[\x{4E00}-\x{9FFF}]/u', $text ) ) {
			return 'zh';
		}
		// Japanese (Hiragana/Katakana).
		if ( preg_match( '/[\x{3040}-\x{30FF}]/u', $text ) ) {
			return 'ja';
		}
		// Korean.
		if ( preg_match( '/[\x{AC00}-\x{D7AF}]/u', $text ) ) {
			return 'ko';
		}
		// Default to English.
		return 'en';
	}

	/**
	 * Check if two texts are essentially the same (ignoring case and punctuation).
	 *
	 * @param string $original   Original text.
	 * @param string $translated Translated text.
	 * @return bool
	 */
	protected function is_same_text( $original, $translated ) {
		$norm = function ( $s ) {
			return mb_strtolower( trim( preg_replace( '/[^\p{L}\p{N}\s]/u', '', $s ) ) );
		};
		return $norm( $original ) === $norm( $translated );
	}
}
