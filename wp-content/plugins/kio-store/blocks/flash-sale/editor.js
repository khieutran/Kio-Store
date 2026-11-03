/**
 * F5 — Phần trong trình soạn thảo của block kio-store/flash-sale.
 *
 * Không có bước build (không React JSX, không npm): dùng các gói có sẵn của WordPress qua
 * biến toàn cục wp.*. Nội dung hiển thị do server render (render.php) nên trong trình soạn
 * thảo chỉ cần <ServerSideRender>; save() trả null vì HTML không lưu trong bài.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'kio-store/flash-sale', {
		edit: function ( props ) {
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Cài đặt Flash Sale', 'kio-store' ) },
						el( RangeControl, {
							label: __( 'Số sản phẩm', 'kio-store' ),
							min: 1,
							max: 20,
							value: props.attributes.limit,
							onChange: function ( value ) {
								props.setAttributes( { limit: value } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'kio-store/flash-sale',
					attributes: props.attributes,
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
