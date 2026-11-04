/**
 * Chọn phiên bản bằng chip (trang sản phẩm biến thể).
 *
 * Ô <select> gốc của Woo vẫn là nguồn sự thật: bấm chip thì đặt giá trị cho select rồi phát sự kiện
 * change, phần còn lại (giá, ảnh, tồn kho, nút mua, trả góp F2, thanh mua F7) chạy như khi khách
 * dùng select. Sau mỗi thay đổi của Woo, chip được vẽ lại từ select nên hai bên không lệch nhau.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $form = $( 'form.variations_form' );
		var groups = $form.find( '.kio-chips' ).get();
		var variations = $form.data( 'product_variations' );
		var priceEl = document.querySelector( '.summary > .price' );
		var defaultPrice = priceEl ? priceEl.innerHTML : '';

		if ( ! groups.length ) {
			return;
		}
		$form.addClass( 'kio-chips-ready' );

		// Sản phẩm quá nhiều biến thể thì Woo không in dữ liệu vào trang (data = false): chip vẫn
		// chọn được, chỉ không có giá từng lựa chọn và trạng thái hết hàng.
		if ( ! Array.isArray( variations ) ) {
			variations = [];
		}

		function selectOf( name ) {
			return $form.find( 'select' ).filter( function () {
				return this.name === name;
			} );
		}

		function currentChoice() {
			var choice = {};
			groups.forEach( function ( group ) {
				choice[ group.dataset.attribute ] = selectOf( group.dataset.attribute ).val() || '';
			} );
			return choice;
		}

		// Biến thể có giá trị '' ở một thuộc tính nghĩa là "bất kỳ" (Any) trong Woo.
		function matching( choice ) {
			return variations.filter( function ( variation ) {
				return Object.keys( choice ).every( function ( name ) {
					var value = variation.attributes[ name ];
					return ! choice[ name ] || ! value || value === choice[ name ];
				} );
			} );
		}

		function formatPrice( amount ) {
			return Number( amount ).toLocaleString( 'vi-VN' ) + ' ₫';
		}

		function refresh() {
			var choice = currentChoice();

			groups.forEach( function ( group ) {
				var name = group.dataset.attribute;

				group.querySelectorAll( '.kio-chip' ).forEach( function ( chip ) {
					var input = chip.querySelector( 'input' );
					var candidate = Object.assign( {}, choice );
					var alone;
					var found;
					var inStock;
					var chipPrice = chip.querySelector( '.kio-chip__price' );

					candidate[ name ] = input.value;
					found = matching( candidate );

					// Tổ hợp không tồn tại khác với hết hàng: bấm vào sẽ tự đổi sang cấu hình đi được
					// (xem choose()), nên xét theo riêng giá trị này.
					if ( ! found.length ) {
						alone = {};
						alone[ name ] = input.value;
						found = matching( alone );
					}

					inStock = found.filter( function ( variation ) {
						return variation.is_in_stock;
					} );

					input.checked = choice[ name ] === input.value;
					chip.classList.toggle( 'is-selected', input.checked );

					if ( variations.length ) {
						chip.classList.toggle( 'is-out', ! inStock.length );
						chip.querySelector( '.kio-chip__state' ).textContent = inStock.length ? '' : 'Hết hàng';
					}

					if ( chipPrice && found.length ) {
						chipPrice.textContent = formatPrice(
							Math.min.apply( null, ( inStock.length ? inStock : found ).map( function ( variation ) {
								return variation.display_price;
							} ) )
						);
					}
				} );
			} );
		}

		// Woo xoá khỏi select những giá trị không đi được với lựa chọn hiện tại, nên .val() với giá
		// trị đó sẽ thất bại âm thầm. Khi đó bỏ hết lựa chọn để Woo dựng lại đủ danh sách rồi mới đặt.
		function applyChoice( choice ) {
			var missing = Object.keys( choice ).some( function ( name ) {
				return choice[ name ] && ! selectOf( name ).find( 'option' ).filter( function () {
					return this.value === choice[ name ];
				} ).length;
			} );

			if ( missing ) {
				$form.find( '.variations select' ).val( '' );
				$form.trigger( 'check_variations' );
			}

			Object.keys( choice ).forEach( function ( name ) {
				selectOf( name ).val( choice[ name ] );
			} );
			selectOf( groups[ groups.length - 1 ].dataset.attribute ).trigger( 'change' );
		}

		function choose( name, value ) {
			var choice = currentChoice();
			var alone = {};
			var fallback;

			choice[ name ] = value;

			// Tổ hợp không tồn tại (vd. MacBook 2 cấu hình): đổi luôn thuộc tính còn lại sang lựa
			// chọn đi được, ưu tiên còn hàng, thay vì để khách gặp thông báo "không có sản phẩm".
			if ( variations.length && ! matching( choice ).length ) {
				alone[ name ] = value;
				fallback = matching( alone ).sort( function ( a, b ) {
					return Number( b.is_in_stock ) - Number( a.is_in_stock );
				} )[ 0 ];
				if ( fallback ) {
					Object.keys( choice ).forEach( function ( key ) {
						if ( key !== name && fallback.attributes[ key ] ) {
							choice[ key ] = fallback.attributes[ key ];
						}
					} );
				}
			}

			applyChoice( choice );
		}

		$form.on( 'change', '.kio-chips input', function () {
			choose( this.closest( '.kio-chips' ).dataset.attribute, this.value );
		} );

		// FlexSlider của gallery nghe phím mũi tên ở document để chuyển ảnh. Phím mũi tên trên chip là
		// để đổi lựa chọn, nên chặn không cho nổi lên: nếu không, ảnh theo màu bị kéo sang ảnh khác.
		$form.on( 'keydown keyup', '.kio-chips input', function ( event ) {
			event.stopPropagation();
		} );

		// Một giá duy nhất ở đầu trang, đổi theo phiên bản. price_html rỗng khi mọi biến thể cùng
		// giá: khi đó giá đầu trang vốn đã đúng.
		$form.on( 'found_variation', function ( event, variation ) {
			if ( priceEl && variation.price_html ) {
				priceEl.innerHTML = $( '<div>' ).html( variation.price_html ).find( '.price' ).html() || variation.price_html;
			}
		} );
		$form.on( 'reset_data', function () {
			if ( priceEl ) {
				priceEl.innerHTML = defaultPrice;
			}
		} );

		$form.on( 'woocommerce_update_variation_values found_variation reset_data', refresh );
		refresh();
	} );
}( jQuery ) );
