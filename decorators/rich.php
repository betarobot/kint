<?php
class Kint_Decorators_Rich extends Kint
{
	# make calls to Kint::dump() from different places in source coloured differently.
	private static $_usedColors = array();

	public static function decorate( kintVariableData $kintVar )
	{
		$output = '<dl>';

		$extendedPresent = $kintVar->extendedValue !== null || $kintVar->alternatives !== null;

		if ( $extendedPresent ) {
			$class = 'kint-parent';
			if ( Kint::$expandedByDefault ) {
				$class .= ' kint-show';
			}
			$output .= '<dt class="' . $class . '"><nav></nav>';
		} else {
			$output .= '<dt>';
		}

		$output .= self::_drawHeader( $kintVar ) . $kintVar->value . '</dt>';


		if ( $extendedPresent ) {
			$output .= '<dd>';
		}

		if ( isset( $kintVar->extendedValue ) ) {

			if ( is_array( $kintVar->extendedValue ) ) {
				foreach ( $kintVar->extendedValue as $v ) {
					$output .= self::decorate( $v );
				}
			} elseif ( is_string( $kintVar->extendedValue ) ) {
				$output .= '<pre>' . $kintVar->extendedValue . '</pre>';
			} else {
				$output .= self::decorate( $kintVar->extendedValue ); //it's kint's container
			}

		} elseif ( isset( $kintVar->alternatives ) ) {
			$output .= "<ul class=\"kint-tabs\">";

			foreach ( $kintVar->alternatives as $k => $var ) {
				$active = $k === 0 ? ' class="kint-active-tab"' : '';
				$output .= "<li{$active}>" . self::_drawHeader( $var, false ) . '</li>';
			}

			$output .= "</ul><ul>";

			foreach ( $kintVar->alternatives as $var ) {
				$output .= "<li>";

				$var = $var->value;

				if ( is_array( $var ) ) {
					foreach ( $var as $v ) {
						$output .= self::decorate( $v );
					}
				} elseif ( is_string( $var ) ) {
					$output .= '<pre>' . $var . '</pre>';
				} elseif ( isset( $var ) ) {
					throw new Exception(
						'Kint has encountered an error, '
						. 'please paste this report to https://github.com/raveren/kint/issues<br>'
						. 'Error encountered at ' . basename( __FILE__ ) . ':' . __LINE__ . '<br>'
						. ' variables: '
						. htmlspecialchars( var_export( $kintVar->alternatives, true ), ENT_QUOTES )
					);
				}

				$output .= "</li>";
			}

			$output .= "</ul>";
		}
		if ( $extendedPresent ) {
			$output .= '</dd>';
		}

		$output .= '</dl>';

		return $output;
	}

	public static function decorateTrace( $traceData )
	{
		$output = '<dl class="kint-trace">';

		foreach ( $traceData as $i => $step ) {
			$class = 'kint-parent';
			if ( Kint::$expandedByDefault ) {
				$class .= ' kint-show';
			}

			$output .= '<dt class="' . $class . '">'
				. '<b>' . ( $i + 1 ) . '</b> '
				. '<nav></nav>'
				. '<var>';

			if ( isset( $step['file'] ) ) {
				$output .= Kint::shortenPath( $step['file'], $step['line'] );
			} else {
				$output .= 'PHP internal call';
			}

			$output .= '</var>';

			$output .= $step['function'];

			if ( isset( $step['args'] ) ) {
				$output .= '(' . implode( ', ', array_keys( $step['args'] ) ) . ')';
			}
			$output .= '</dt><dd>';
			$firstTab = ' class="kint-active-tab"';
			$output .= '<ul class="kint-tabs">';

			if ( !empty( $step['source'] ) ) {
				$output .= "<li{$firstTab}>Source</li>";
				$firstTab = '';
			}

			if ( !empty( $step['args'] ) ) {
				$output .= "<li{$firstTab}>Arguments</li>";
				$firstTab = '';
			}

			if ( !empty( $step['object'] ) ) {
				kintParser::reset();
				$calleDump = kintParser::factory( $step['object'] );

				$output .= "<li{$firstTab}>Callee object [{$calleDump->subtype}]</li>";
			}


			$output .= '</ul><ul>';


			if ( !empty( $step['source'] ) ) {
				$output .= "<li><pre class=\"kint-source\">{$step['source']}</pre></li>";
			}

			if ( !empty( $step['args'] ) ) {
				$output .= "<li>";
				foreach ( $step['args'] as $k => $arg ) {
					kintParser::reset();
					$output .= Kint_Decorators_Rich::decorate( kintParser::factory( $arg, $k ) );
				}
				echo "</li>";
			}
			if ( !empty( $step['object'] ) ) {
				$output .= "<li>" . Kint_Decorators_Rich::decorate( $calleDump ) . "</li>";
			}

			$output .= '</ul></dd>';
		}
		$output .= '</dl>';

		return $output;
	}


	/**
	 * called for each dump, opens the html tag
	 *
	 * @param array $callee caller information taken from debug backtrace
	 *
	 * @return string
	 */
	public static function wrapStart( $callee )
	{
		// colors looping outputs the same (i.e. if same line in code dumps variables multiple time,
		// we assume it's in a loop)

		$uid = isset( $callee['file'] ) ? crc32( $callee['file'] . $callee['line'] ) : 'no-file';

		if ( isset( self::$_usedColors[$uid] ) ) {
			$class = self::$_usedColors[$uid];
		} else {
			$class                   = sizeof( self::$_usedColors );
			self::$_usedColors[$uid] = $class;
		}

		$class = "kint_{$class}";


		return self::_css() . "<div class=\"kint {$class}\">";
	}


	/**
	 * closes Kint::_wrapStart() started html tags and displays callee information
	 *
	 * @param array $callee caller information taken from debug backtrace
	 * @param array $prevCaller previous caller information taken from debug backtrace
	 *
	 * @return string
	 */
	public static function wrapEnd( $callee, $prevCaller )
	{
		if ( !Kint::$displayCalledFrom ) {
			return '</div>';
		}

		$callingFunction = '';
		if ( isset( $prevCaller['class'] ) ) {
			$callingFunction = $prevCaller['class'];
		}
		if ( isset( $prevCaller['type'] ) ) {
			$callingFunction .= $prevCaller['type'];
		}
		if ( isset( $prevCaller['function'] ) && !in_array( $prevCaller['function'], Kint::$_statements ) ) {
			$callingFunction .= $prevCaller['function'] . '()';
		}
		$callingFunction and $callingFunction = " in ({$callingFunction})";


		$calleeInfo = isset( $callee['file'] )
			? 'Called from ' . self::shortenPath( $callee['file'], $callee['line'] )
			: '';


		return $calleeInfo || $callingFunction
			? "<footer>{$calleeInfo}{$callingFunction}</footer></div>"
			: "</div>";
	}


	private static function _drawHeader( kintVariableData $kintVar, $verbose = true )
	{
		$output = '';
		if ( $verbose ) {
			if ( $kintVar->access !== null ) {
				$output .= "<var>" . $kintVar->access . "</var> ";
			}

			if ( $kintVar->name !== null && $kintVar->name !== '' ) {
				$output .= "<dfn>" . $kintVar->name . "</dfn> ";
			}

			if ( $kintVar->operator !== null ) {
				$output .= $kintVar->operator . " ";
			}
		}

		if ( $kintVar->type !== null ) {
			$output .= "<var>" . $kintVar->type;
			if ( $kintVar->subtype !== null ) {
				$output .= " " . $kintVar->subtype;
			}
			$output .= "</var> ";
		}


		if ( $kintVar->size !== null ) {
			$output .= "(" . $kintVar->size . ") ";
		}

		return $output;
	}


	/**
	 * produces css and js required for display. May be called multiple times, will only produce output once per
	 * pageload or until `-` or `@` modifier is used
	 *
	 * @return string
	 */
	private static function _css()
	{
		if ( !self::$_firstRun ) return '';
		self::$_firstRun = false;

		$baseDir = KINT_DIR . 'view/inc/';

		if ( !is_readable( $cssFile = $baseDir . self::$theme . '.css' ) ) {
			$cssFile = $baseDir . 'original.css';
		}

		return '<script>' . file_get_contents( $baseDir . 'kint.js' ) . '</script>'
		. '<style>' . file_get_contents( $cssFile ) . "</style>\n";
	}
}