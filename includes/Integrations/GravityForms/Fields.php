<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Integrations\GravityForms;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the translatable strings of a Gravity Forms form.
 *
 * Gravity Forms stores forms in its own tables (not as posts), so we can't ride
 * Polylang's post pipeline like the YOOtheme integration does. Instead we collect
 * the visible strings by a stable path, translate them, and write them back onto
 * the in-memory form object at render time.
 *
 * Paths look like `title`, `button.text`, `field.7.label`,
 * `field.7.choice.2.text`, `field.9.input.1.label` — keyed by field *id* (not
 * position) so they survive field reordering.
 *
 * @since 0.3.0
 */
class Fields {
	/**
	 * Collects the translatable source strings of a form, keyed by path.
	 *
	 * @param array $form Gravity Forms form (fields are GF_Field objects).
	 * @return array<string, string> Non-empty source strings keyed by path.
	 */
	public static function collect( array $form ): array {
		$out = array();

		self::add( $out, 'title', $form['title'] ?? '' );
		self::add( $out, 'description', $form['description'] ?? '' );
		if ( isset( $form['button']['text'] ) ) {
			self::add( $out, 'button.text', $form['button']['text'] );
		}

		foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
			$id = self::field_id( $field );
			if ( '' === $id ) {
				continue;
			}

			self::add( $out, "field.$id.label", self::get( $field, 'label' ) );
			self::add( $out, "field.$id.description", self::get( $field, 'description' ) );
			self::add( $out, "field.$id.placeholder", self::get( $field, 'placeholder' ) );
			self::add( $out, "field.$id.errorMessage", self::get( $field, 'errorMessage' ) );
			self::add( $out, "field.$id.content", self::get( $field, 'content' ) );

			foreach ( (array) self::get_array( $field, 'choices' ) as $j => $choice ) {
				if ( is_array( $choice ) && isset( $choice['text'] ) ) {
					self::add( $out, "field.$id.choice.$j.text", $choice['text'] );
				}
			}
			foreach ( (array) self::get_array( $field, 'inputs' ) as $k => $input ) {
				if ( is_array( $input ) && isset( $input['label'] ) ) {
					self::add( $out, "field.$id.input.$k.label", $input['label'] );
				}
				if ( is_array( $input ) && isset( $input['placeholder'] ) ) {
					self::add( $out, "field.$id.input.$k.placeholder", $input['placeholder'] );
				}
			}
		}

		return $out;
	}

	/**
	 * Applies a path => translation map onto a form, returning the modified form.
	 *
	 * Only non-empty translations are written; missing paths keep the source value.
	 *
	 * @param array                  $form Gravity Forms form (fields are GF_Field objects).
	 * @param array<string, string>  $map  Translations keyed by path.
	 * @return array
	 */
	public static function apply( array $form, array $map ): array {
		if ( empty( $map ) ) {
			return $form;
		}

		if ( self::has( $map, 'title' ) ) {
			$form['title'] = $map['title'];
		}
		if ( self::has( $map, 'description' ) ) {
			$form['description'] = $map['description'];
		}
		if ( self::has( $map, 'button.text' ) && isset( $form['button'] ) && is_array( $form['button'] ) ) {
			$form['button']['text'] = $map['button.text'];
		}

		foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
			$id = self::field_id( $field );
			if ( '' === $id ) {
				continue;
			}

			foreach ( array( 'label', 'description', 'placeholder', 'errorMessage', 'content' ) as $prop ) {
				if ( self::has( $map, "field.$id.$prop" ) ) {
					self::set( $field, $prop, $map[ "field.$id.$prop" ] );
				}
			}

			$choices = self::get_array( $field, 'choices' );
			if ( is_array( $choices ) ) {
				foreach ( $choices as $j => $choice ) {
					if ( is_array( $choice ) && self::has( $map, "field.$id.choice.$j.text" ) ) {
						$choices[ $j ]['text'] = $map[ "field.$id.choice.$j.text" ];
					}
				}
				self::set( $field, 'choices', $choices );
			}

			$inputs = self::get_array( $field, 'inputs' );
			if ( is_array( $inputs ) ) {
				foreach ( $inputs as $k => $input ) {
					if ( is_array( $input ) && self::has( $map, "field.$id.input.$k.label" ) ) {
						$inputs[ $k ]['label'] = $map[ "field.$id.input.$k.label" ];
					}
					if ( is_array( $input ) && self::has( $map, "field.$id.input.$k.placeholder" ) ) {
						$inputs[ $k ]['placeholder'] = $map[ "field.$id.input.$k.placeholder" ];
					}
				}
				self::set( $field, 'inputs', $inputs );
			}
		}

		return $form;
	}

	/**
	 * Adds a trimmed, non-empty value to the map.
	 *
	 * @param array<string,string> $out   Map (by reference).
	 * @param string               $path  Path key.
	 * @param mixed                $value Value.
	 * @return void
	 */
	private static function add( array &$out, string $path, $value ): void {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$out[ $path ] = $value;
		}
	}

	/**
	 * Whether the map has a usable (non-empty) translation for a path.
	 *
	 * @param array<string,string> $map  Map.
	 * @param string               $path Path.
	 * @return bool
	 */
	private static function has( array $map, string $path ): bool {
		return isset( $map[ $path ] ) && '' !== trim( (string) $map[ $path ] );
	}

	/**
	 * Returns a field's id as a string, or '' if unavailable.
	 *
	 * @param mixed $field GF_Field object or array.
	 * @return string
	 */
	private static function field_id( $field ): string {
		if ( is_object( $field ) && isset( $field->id ) ) {
			return (string) $field->id;
		}
		if ( is_array( $field ) && isset( $field['id'] ) ) {
			return (string) $field['id'];
		}
		return '';
	}

	/**
	 * Reads a string property from a GF_Field object or array.
	 *
	 * @param mixed  $field GF_Field object or array.
	 * @param string $prop  Property name.
	 * @return string
	 */
	private static function get( $field, string $prop ): string {
		if ( is_object( $field ) ) {
			return isset( $field->$prop ) ? (string) $field->$prop : '';
		}
		if ( is_array( $field ) ) {
			return isset( $field[ $prop ] ) ? (string) $field[ $prop ] : '';
		}
		return '';
	}

	/**
	 * Reads an array property (choices / inputs) from a GF_Field object or array.
	 *
	 * @param mixed  $field GF_Field object or array.
	 * @param string $prop  Property name.
	 * @return array|null
	 */
	private static function get_array( $field, string $prop ) {
		if ( is_object( $field ) && isset( $field->$prop ) && is_array( $field->$prop ) ) {
			return $field->$prop;
		}
		if ( is_array( $field ) && isset( $field[ $prop ] ) && is_array( $field[ $prop ] ) ) {
			return $field[ $prop ];
		}
		return null;
	}

	/**
	 * Writes a property onto a GF_Field object or array (by reference).
	 *
	 * @param mixed  $field GF_Field object or array.
	 * @param string $prop  Property name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private static function set( $field, string $prop, $value ): void {
		if ( is_object( $field ) ) {
			$field->$prop = $value;
		}
	}
}
