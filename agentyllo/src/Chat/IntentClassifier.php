<?php
/**
 * Deterministic intent classification.
 *
 * @package Agentyllo
 */

declare( strict_types=1 );

namespace Agentyllo\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Two layers, precision first.
 *
 * Layer A: ordered regex pattern packs per intent (en + it/de/fr/es/pt/nl
 * variants) — when a pattern fires it wins at 0.9. Pack order encodes
 * priority: handoff outranks everything ("I want a HUMAN, not this bot"),
 * greeting/smalltalk only match when they ARE the whole message, so mixed
 * messages ("hi, how much is X?") fall through to the info intents.
 *
 * Layer B: entity-informed fallback — product entities → product_query at
 * 0.7; page entities → site_info at 0.7; otherwise site_info at 0.4, which
 * falls through to retrieval. 'unknown' never surfaces to visitors.
 *
 * Addons extend via the agyl_intents filter (add packs, prepend patterns);
 * invalid addon regexes are skipped defensively.
 */
final class IntentClassifier {

	private const PATTERN_CONFIDENCE = 0.9;
	private const ENTITY_CONFIDENCE  = 0.7;
	private const FLOOR_CONFIDENCE   = 0.4;

	/**
	 * Classify a message.
	 *
	 * @param string $text_lc  Lowercased normalized text.
	 * @param array  $entities EntityExtractor output.
	 * @return array{intent: string, confidence: float}
	 */
	public function classify( string $text_lc, array $entities ): array {
		if ( '' !== trim( $text_lc ) ) {
			/**
			 * Filter the intent pattern packs (intent => regex list, matched
			 * in array order — first hit wins). Addons may add intents or
			 * patterns; keys become the classified intent verbatim.
			 *
			 * @param array<string, string[]> $patterns Ordered pattern packs.
			 */
			$patterns = apply_filters( 'agyl_intents', $this->default_patterns() );

			foreach ( (array) $patterns as $intent => $pack ) {
				if ( ! is_string( $intent ) || '' === $intent || ! is_array( $pack ) ) {
					continue;
				}
				foreach ( $pack as $pattern ) {
					if ( ! is_string( $pattern ) || '' === $pattern ) {
						continue;
					}
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- addon-supplied regex may be invalid; a warning must not break chat.
					if ( 1 === @preg_match( $pattern, $text_lc ) ) {
						return array(
							'intent'     => $intent,
							'confidence' => self::PATTERN_CONFIDENCE,
						);
					}
				}
			}
		}

		if ( ! empty( $entities['products'] ) || ! empty( $entities['skus'] ) ) {
			return array(
				'intent'     => 'product_query',
				'confidence' => self::ENTITY_CONFIDENCE,
			);
		}

		return array(
			'intent'     => 'site_info',
			'confidence' => ! empty( $entities['pages'] ) ? self::ENTITY_CONFIDENCE : self::FLOOR_CONFIDENCE,
		);
	}

	/**
	 * Built-in pattern packs. Matched against lowercased text, so the /i flag
	 * is belt-and-braces only; /u keeps \b and classes multibyte-safe.
	 *
	 * Pack order IS precedence: handoff first, then navigation (its multi-
	 * word constructs are more precise than the single-keyword packs — in
	 * "where can I find the pricing page?" the navigational phrasing must
	 * beat the 'pricing' keyword), then the keyword packs, then the
	 * whole-message-anchored greeting/smalltalk.
	 *
	 * @return array<string, string[]>
	 */
	private function default_patterns(): array {
		return array(
			'handoff'              => array(
				'/\b(?:talk|speak|chat)\s+(?:to|with)\s+(?:an?\s+)?(?:human|person|agent|operator|representative|somebody|someone)\b/iu',
				'/\b(?:real\s+person|live\s+agent|human\s+(?:being|support|agent)|not\s+a\s+bot)\b/iu',
				'/\b(?:operatore|assistenza\s+umana|persona\s+reale|parlare\s+con\s+(?:un|una|qualcuno))\b/iu',
				'/\b(?:hablar\s+con\s+(?:un|una|alguien)|persona\s+real|operador(?:a)?|atendente|falar\s+com\s+(?:um|uma|algu[ée]m))\b/iu',
				'/\b(?:parler\s+[àa]\s+(?:un|une|quelqu)|op[ée]rateur|un\s+humain|une\s+vraie\s+personne)\b/iu',
				'/\b(?:mit\s+einem\s+(?:menschen|mitarbeiter)\s+sprechen|echter\s+mensch|echte\s+person)\b/iu',
				'/\b(?:met\s+een\s+(?:mens|medewerker)\s+(?:praten|spreken)|echt\s+persoon)\b/iu',
			),
			'navigation_find_page' => array(
				'/\bwhere\s+(?:can\s+i|do\s+i|is|are)\b.*\b(?:find|page|section)\b/iu',
				'/\b(?:link|url)\s+(?:to|for|of)\b/iu',
				'/\b(?:show\s+me\s+the\s+page|take\s+me\s+to|go\s+to\s+the\s+page)\b/iu',
				'/\b(?:show\s+me|open|go\s+to|bring\s+me\s+to|navigate\s+to)\b.*\b(?:page|section)\b/iu',
				'/\b(?:dove\s+(?:trovo|si\s+trova|posso\s+trovare)|mostrami\s+la\s+pagina|portami\s+(?:a|alla))\b/iu',
				'/\b(?:wo\s+finde\s+ich|zeig\s+mir\s+die\s+seite|link\s+zu[rm]?)\b/iu',
				'/\b(?:o[ùu]\s+(?:puis-je\s+)?trouver|montrez?-moi\s+la\s+page|lien\s+vers)\b/iu',
				'/\b(?:d[óo]nde\s+(?:puedo\s+)?encontrar|mu[ée]strame\s+la\s+p[áa]gina|enlace\s+a)\b/iu',
				'/\b(?:onde\s+(?:posso\s+)?encontrar|mostre?-me\s+a\s+p[áa]gina)\b/iu',
				'/\b(?:waar\s+(?:kan\s+ik|vind\s+ik)|laat\s+me\s+de\s+pagina\s+zien)\b/iu',
			),
			'contact'              => array(
				'/\b(?:contact|reach\s+you|call\s+you|get\s+in\s+touch|e-?mail(?:\s+address)?|phone(?:\s+number)?|telephone)\b/iu',
				'/\b(?:contatt\w*|telefono|chiamarvi|come\s+vi\s+raggiungo|indirizzo\s+e-?mail)\b/iu',
				'/\b(?:kontakt\w*|telefonnummer|anrufen|erreichen\s+(?:wir|sie|euch))\b/iu',
				'/\b(?:contacter|joindre|t[ée]l[ée]phone|courriel|coordonn[ée]es)\b/iu',
				'/\b(?:contacto|contactar|tel[ée]fono|correo(?:\s+electr[óo]nico)?|llamar(?:les|los)?)\b/iu',
				'/\b(?:contato|contatar|telefone|ligar\s+para)\b/iu',
				'/\b(?:contacteren|telefoonnummer|bellen|bereiken)\b/iu',
			),
			'hours_policy'         => array(
				'/\b(?:opening\s+hours|open(?:ing)?\s+times?|business\s+hours|(?:are|is)\s+(?:you|it|the\s+\w+)\s+open|when\s+(?:do\s+you|are\s+you)\s+(?:open|close)|closing\s+time)\b/iu',
				'/\b(?:refunds?|return\s+policy|returns?|exchanges?|shipping|delivery(?:\s+times?)?|warranty|guarantee|privacy\s+policy|terms\s+(?:of|and)|cancellation)\b/iu',
				'/\b(?:orari(?:o)?(?:\s+di\s+apertura)?|siete\s+aperti|quando\s+(?:aprite|chiudete)|aperto|chiuso|rimbors\w*|res[oi]|cambio\s+merce|spedizion\w*|consegna|garanzia|recesso)\b/iu',
				'/\b(?:[öo]ffnungszeiten|ge[öo]ffnet|geschlossen|r[üu]ckgabe|erstattung|umtausch|versand|lieferung|garantie|widerruf)\b/iu',
				'/\b(?:horaires?|ouvert\w*|ferm[ée]\w*|remboursement|retours?|[ée]change|livraison|exp[ée]dition|garantie|annulation)\b/iu',
				'/\b(?:horarios?|abierto|cerrado|reembolso|devoluci[óo]n(?:es)?|env[íi]o|entrega|garant[íi]a|cancelaci[óo]n)\b/iu',
				'/\b(?:hor[áa]rio\s+de\s+funcionamento|aberto|fechado|reembolso|devolu[çc][ãa]o|troca|frete|entrega|garantia)\b/iu',
				'/\b(?:openingstijden|geopend|gesloten|terugbetaling|retourneren|retourbeleid|verzending|levering|garantie)\b/iu',
			),
			'price_stock'          => array(
				'/\b(?:price|prices|pricing|cost|costs|how\s+much|in\s+stock|out\s+of\s+stock|stock|availab(?:le|ility)|discount|sale|cheap(?:er|est)?)\b/iu',
				'/\b(?:prezz\w*|quanto\s+cost\w*|costo|disponibil\w*|in\s+magazzino|sconto|offerta|economico)\b/iu',
				'/\b(?:preis\w*|was\s+kostet|kostet|kosten|verf[üu]gbar\w*|auf\s+lager|lieferbar|rabatt|angebot)\b/iu',
				'/\b(?:prix|co[ûu]te|combien\s+(?:co[ûu]te|pour)|disponible|en\s+stock|promotion|remise)\b/iu',
				'/\b(?:precios?|cu[áa]nto\s+(?:cuesta|vale)|disponible|en\s+stock|existencias|descuento|oferta|barato)\b/iu',
				'/\b(?:pre[çc]os?|quanto\s+custa|dispon[íi]vel|em\s+estoque|desconto|promo[çc][ãa]o|barato)\b/iu',
				'/\b(?:prijs|prijzen|wat\s+kost|kost(?:en)?|voorraad|op\s+voorraad|beschikbaar|leverbaar|korting|aanbieding)\b/iu',
			),
			'greeting'             => array(
				// Whole-message greeting, optionally followed by ONE short filler
				// ("hi there!", "hello everyone", "ciao a tutti") — but never a
				// real question ("hi, how much is X?" → price_stock wins).
				'/^(?:hi|hello|hey|howdy|good\s+(?:morning|afternoon|evening)|ciao|salve|buongiorno|buonasera|bonjour|bonsoir|salut|hola|buenas|buenos\s+d[íi]as|buenas\s+tardes|hallo|hoi|guten\s+(?:tag|morgen|abend)|moin|ol[áa]|bom\s+dia|boa\s+tarde|goedemorgen|goedemiddag|goedendag)(?:[\s,!]+(?:there|everyone|everybody|all|you|team|guys|folks|bot|assistant|a\s+tutti|à\s+tous|zusammen|allemaal|a\s+todos))?[^\p{L}]*$/iu',
			),
			'smalltalk'            => array(
				'/\b(?:how\s+are\s+you|thank\s+you|thanks(?:\s+a\s+lot)?|come\s+stai|come\s+va|grazie(?:\s+mille)?|wie\s+geht(?:\'s|\s+es)|danke(?:\s+sch[öo]n|sehr)?|comment\s+[çc]a\s+va|merci(?:\s+beaucoup)?|c[óo]mo\s+est[áa]s?|qu[ée]\s+tal|gracias|como\s+vai|tudo\s+bem|obrigad[oa]|hoe\s+gaat\s+het|bedankt|dank\s+je(?:\s+wel)?)\b/iu',
				'/^(?:ok|okay|great|nice|cool|perfect|perfetto|bene|va\s+bene|super|g[ée]nial|genial|top|prima|goed)[^\p{L}]*$/iu',
			),
		);
	}
}
