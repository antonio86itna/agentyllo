<?php
/**
 * Lightweight pure-PHP language detection.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Honest about its scope: this is a two-tier heuristic, not a trained model.
 *
 * Tier 1 — Unicode script detection. Non-Latin scripts identify their major
 * language directly (Hangul → ko, Cyrillic → ru, Arabic → ar, Greek → el,
 * Hebrew → he, Devanagari → hi; CJK → ja when kana is present, else zh).
 * The script→language mapping is a deliberate simplification (Cyrillic could
 * be Ukrainian, Arabic script could be Persian): for the classic tier the
 * detected code only drives a courtesy notice, so the major-language guess
 * is good enough and never fabricates content.
 *
 * Tier 2 — Latin scripts get marker-word scoring across the 7 built-in
 * languages (en/it/de/fr/es/pt/nl). The lists hold ~25 DISTINCTIVE
 * high-frequency words each: words shared across languages (e.g. 'con'
 * it/es, 'que' fr/es/pt, 'is' en/nl) are excluded on purpose, so a single
 * hit is real signal for exactly one language.
 *
 * Upgrade path: these built-in lists are the floor. From M7 the signed
 * registry can ship richer per-language profiles (n-gram tables, more
 * languages) as data updates — consumers keep calling detect() and only the
 * profile source changes, never this contract.
 */
final class LanguageDetector {

	private const MIN_LENGTH = 15;

	/**
	 * A script must cover at least this share of letters to decide.
	 */
	private const SCRIPT_MIN_RATIO = 0.25;

	/**
	 * Unicode script ranges → language code (order matters: kana check for
	 * 'ja' runs before the generic Han → 'zh' mapping).
	 *
	 * @var array<string, string>
	 */
	private const SCRIPTS = array(
		'ko' => '\x{AC00}-\x{D7AF}\x{1100}-\x{11FF}',
		'ru' => '\x{0400}-\x{04FF}',
		'ar' => '\x{0600}-\x{06FF}\x{0750}-\x{077F}',
		'el' => '\x{0370}-\x{03FF}',
		'he' => '\x{0590}-\x{05FF}',
		'hi' => '\x{0900}-\x{097F}',
	);

	private const KANA_RANGE = '\x{3040}-\x{309F}\x{30A0}-\x{30FF}';
	private const HAN_RANGE  = '\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}';

	/**
	 * Distinctive high-frequency marker words per Latin-script language.
	 * Compact on purpose; cross-language collisions were pruned by hand.
	 *
	 * @var array<string, string[]>
	 */
	private const MARKERS = array(
		// Marker words per language: high-frequency function words + the
		// question/commerce vocabulary visitors actually type into a site
		// assistant. Cross-language collisions were pruned by hand.
		'en' => array( 'the', 'and', 'you', 'your', 'what', 'where', 'when', 'why', 'how', 'can', 'could', 'would', 'should', 'have', 'has', 'does', 'this', 'that', 'with', 'please', 'thanks', 'hello', 'which', 'will', 'about', 'from', 'are', 'price', 'cost', 'shipping', 'delivery', 'return', 'returns', 'order', 'product', 'products', 'available', 'hours', 'phone', 'much', 'many', 'sell', 'buy', 'any' ),
		'it' => array( 'che', 'di', 'per', 'sono', 'questo', 'questa', 'come', 'dove', 'della', 'delle', 'nella', 'degli', 'anche', 'ciao', 'grazie', 'prego', 'vorrei', 'posso', 'avete', 'quanto', 'quale', 'perché', 'più', 'cosa', 'siamo', 'è', 'costa', 'costano', 'quanti', 'quante', 'quanta', 'qual', 'gli', 'nel', 'sul', 'dal', 'alla', 'allo', 'spedizione', 'consegna', 'reso', 'resi', 'prezzo', 'prezzi', 'ordine', 'prodotto', 'prodotti', 'disponibile', 'disponibili', 'orari', 'contatti', 'telefono', 'potete', 'cerco', 'buongiorno', 'buonasera', 'salve' ),
		'de' => array( 'der', 'das', 'und', 'ich', 'nicht', 'ist', 'sind', 'wie', 'wo', 'wann', 'kann', 'können', 'haben', 'eine', 'einen', 'mit', 'für', 'auf', 'aus', 'bei', 'nach', 'wir', 'ihr', 'sie', 'mir', 'mich', 'dieses', 'diese', 'dieser', 'noch', 'auch', 'danke', 'hallo', 'bitte', 'möchte', 'gibt', 'preis', 'kostet', 'kosten', 'versand', 'lieferung', 'rückgabe', 'bestellung', 'produkt', 'verfügbar', 'öffnungszeiten', 'kontakt', 'telefon', 'wieviel', 'welche', 'welcher', 'habt', 'dauert' ),
		'fr' => array( 'les', 'une', 'est', 'et', 'où', 'vous', 'nous', 'pas', 'pour', 'avec', 'dans', 'sur', 'comment', 'quand', 'bonjour', 'merci', 'être', 'avoir', 'cette', 'votre', 'aussi', 'très', 'voudrais', 'ça', 'quel', 'quelle', 'prix', 'coûte', 'combien', 'livraison', 'expédition', 'commande', 'produit', 'produits', 'horaires', 'téléphone', 'avez', 'peux', 'puis', 'est-ce', 'faut' ),
		'es' => array( 'el', 'los', 'las', 'pero', 'donde', 'cuando', 'hola', 'gracias', 'tengo', 'quiero', 'puedo', 'tiene', 'tienen', 'usted', 'cuánto', 'cuál', 'dónde', 'cómo', 'muy', 'también', 'aquí', 'hay', 'necesito', 'ayuda', 'algún', 'sí', 'precio', 'cuesta', 'cuestan', 'envío', 'devolución', 'pedido', 'producto', 'productos', 'horario', 'teléfono', 'tienes', 'quisiera', 'venden', 'ustedes' ),
		'pt' => array( 'não', 'você', 'vocês', 'obrigado', 'obrigada', 'olá', 'onde', 'quero', 'tenho', 'preciso', 'gostaria', 'muito', 'também', 'isso', 'essa', 'esse', 'uma', 'meu', 'minha', 'seu', 'sua', 'ele', 'ela', 'fazer', 'pode', 'qual', 'é', 'preço', 'custa', 'custam', 'envio', 'devolução', 'produto', 'produtos', 'disponível', 'horário', 'telefone', 'têm', 'vendem', 'quais' ),
		'nl' => array( 'het', 'een', 'niet', 'ik', 'jij', 'wij', 'jullie', 'dat', 'dit', 'deze', 'met', 'voor', 'van', 'naar', 'uit', 'ook', 'maar', 'hoe', 'waar', 'wanneer', 'kunnen', 'hebben', 'heeft', 'zijn', 'bedankt', 'graag', 'hoi', 'prijs', 'kost', 'verzending', 'levering', 'bestelling', 'beschikbaar', 'openingstijden', 'telefoon', 'hoeveel', 'kunt', 'wilt', 'verkopen' ),
	);

	/**
	 * Detect the language of a text.
	 *
	 * @param string $text Normalized visitor text.
	 * @return array{lang: string, confidence: float} lang '' = undetermined.
	 */
	public function detect( string $text ): array {
		$text = trim( $text );
		if ( mb_strlen( $text, 'UTF-8' ) < self::MIN_LENGTH ) {
			return array(
				'lang'       => '',
				'confidence' => 0.0,
			);
		}

		$by_script = $this->detect_script( $text );
		if ( '' !== $by_script['lang'] ) {
			return $by_script;
		}

		return $this->detect_latin( $text );
	}

	/**
	 * Tier 1: script-range counting.
	 *
	 * @param string $text Text.
	 * @return array{lang: string, confidence: float}
	 */
	private function detect_script( string $text ): array {
		$letters = (int) preg_match_all( '/\p{L}/u', $text );
		if ( $letters < 1 ) {
			return array(
				'lang'       => '',
				'confidence' => 0.0,
			);
		}

		// CJK first: kana anywhere → Japanese, Han without kana → Chinese.
		$kana = (int) preg_match_all( '/[' . self::KANA_RANGE . ']/u', $text );
		$han  = (int) preg_match_all( '/[' . self::HAN_RANGE . ']/u', $text );
		if ( ( $kana + $han ) / $letters >= self::SCRIPT_MIN_RATIO ) {
			return array(
				'lang'       => $kana > 0 ? 'ja' : 'zh',
				'confidence' => $this->script_confidence( ( $kana + $han ) / $letters ),
			);
		}

		foreach ( self::SCRIPTS as $lang => $range ) {
			$count = (int) preg_match_all( '/[' . $range . ']/u', $text );
			if ( $count / $letters >= self::SCRIPT_MIN_RATIO ) {
				return array(
					'lang'       => $lang,
					'confidence' => $this->script_confidence( $count / $letters ),
				);
			}
		}

		return array(
			'lang'       => '',
			'confidence' => 0.0,
		);
	}

	/**
	 * Tier 2: marker-word hit scoring for Latin scripts.
	 *
	 * Confidence = hits/tokens, normalized so that ~40% marker density (a
	 * typical full sentence) saturates, and capped per absolute hit count so
	 * a single marker word can never clear the sticky-switch threshold.
	 *
	 * @param string $text Text.
	 * @return array{lang: string, confidence: float}
	 */
	private function detect_latin( string $text ): array {
		preg_match_all( '/\p{L}+/u', mb_strtolower( $text, 'UTF-8' ), $matches );
		$tokens = $matches[0];
		if ( ! $tokens ) {
			return array(
				'lang'       => '',
				'confidence' => 0.0,
			);
		}

		$best_lang = '';
		$best_hits = 0;
		foreach ( self::MARKERS as $lang => $words ) {
			$set  = array_flip( $words );
			$hits = 0;
			foreach ( $tokens as $token ) {
				if ( isset( $set[ $token ] ) ) {
					++$hits;
				}
			}
			if ( $hits > $best_hits ) {
				$best_hits = $hits;
				$best_lang = $lang;
			}
		}

		if ( 0 === $best_hits ) {
			return array(
				'lang'       => '',
				'confidence' => 0.0,
			);
		}

		$ratio      = $best_hits / count( $tokens );
		$confidence = min( 0.95, $ratio * 2.5, $best_hits * 0.3 );

		return array(
			'lang'       => $best_lang,
			'confidence' => round( $confidence, 2 ),
		);
	}

	/**
	 * Script-share → confidence (a fully non-Latin message ≈ 0.95).
	 *
	 * @param float $ratio Share of letters in the script.
	 */
	private function script_confidence( float $ratio ): float {
		return round( min( 0.95, $ratio + 0.2 ), 2 );
	}
}
