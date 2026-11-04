/**
 * Quét tương phản chữ theo WCAG AA: chữ thường 4,5:1, chữ lớn 3:1.
 *
 * Site phải đang chạy. Máy không cần cài thêm gì ngoài playwright-cli:
 *   playwright-cli -s=audit open about:blank
 *   playwright-cli -s=audit resize 1440 900
 *   playwright-cli --raw -s=audit run-code --filename=scripts/contrast-audit.js
 *   playwright-cli -s=audit close
 *
 * Trả về JSON { samples, failures }. Quét cả chữ gợi ý trong ô nhập và 3 loại thông báo của Woo.
 * Đo cả lúc rê chuột trên các phần tử bấm được (danh sách HOVER ở dưới).
 * Không đo: chữ nằm trong ảnh. Nền ảnh/gradient được bỏ qua vì không tính được.
 */
async page => {
	const site = 'http://localhost:8080';
	const failures = {};
	let samples = 0;

	const scan = ( selector ) => page.evaluate( ( root ) => {
		const parse = ( text ) => {
			const match = text.match( /rgba?\(([^)]+)\)/ );
			if ( ! match ) {
				return null;
			}
			const p = match[ 1 ].split( /[ ,/]+/ ).map( Number );
			return { r: p[ 0 ], g: p[ 1 ], b: p[ 2 ], a: undefined === p[ 3 ] ? 1 : p[ 3 ] };
		};
		const over = ( top, under ) => ( {
			r: top.r * top.a + under.r * ( 1 - top.a ),
			g: top.g * top.a + under.g * ( 1 - top.a ),
			b: top.b * top.a + under.b * ( 1 - top.a ),
			a: 1,
		} );
		const luminance = ( c ) => {
			const f = ( v ) => {
				v /= 255;
				return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
			};
			return 0.2126 * f( c.r ) + 0.7152 * f( c.g ) + 0.0722 * f( c.b );
		};
		const ratio = ( a, b ) => {
			const x = luminance( a );
			const y = luminance( b );
			return ( Math.max( x, y ) + 0.05 ) / ( Math.min( x, y ) + 0.05 );
		};
		const hex = ( c ) => '#' + [ c.r, c.g, c.b ].map( ( v ) => Math.round( v ).toString( 16 ).padStart( 2, '0' ) ).join( '' );
		const background = ( element ) => {
			const layers = [];
			let image = false;
			for ( let node = element; node; node = node.parentElement ) {
				const style = getComputedStyle( node );
				if ( 'none' !== style.backgroundImage && ! style.backgroundImage.includes( 'data:' ) ) {
					image = true;
				}
				const color = parse( style.backgroundColor );
				if ( color && color.a > 0 ) {
					layers.push( color );
					if ( 1 === color.a ) {
						break;
					}
				}
			}
			let result = { r: 255, g: 255, b: 255, a: 1 };
			layers.reverse().forEach( ( layer ) => {
				result = over( layer, result );
			} );
			return { color: result, image };
		};
		const name = ( element ) => {
			const classes = [ ...element.classList ].slice( 0, 2 ).join( '.' );
			return element.tagName.toLowerCase() + ( classes ? '.' + classes : '' );
		};

		const rows = [];
		const add = ( element, foreground, text ) => {
			const { color, image } = background( element );
			if ( image ) {
				return;
			}
			const style = getComputedStyle( element );
			let opacity = 1;
			for ( let node = element; node; node = node.parentElement ) {
				opacity *= parseFloat( getComputedStyle( node ).opacity );
			}
			const shown = over( { ...foreground, a: foreground.a * opacity }, color );
			const size = parseFloat( style.fontSize );
			const large = size >= 24 || ( size >= 18.66 && parseInt( style.fontWeight, 10 ) >= 700 );
			rows.push( {
				who: name( element ),
				fg: hex( shown ),
				bg: hex( color ),
				ratio: Number( ratio( shown, color ).toFixed( 2 ) ),
				need: large ? 3 : 4.5,
				text: text.slice( 0, 30 ),
			} );
		};

		const walker = document.createTreeWalker( root ? document.querySelector( root ) : document.body, NodeFilter.SHOW_TEXT );
		while ( walker.nextNode() ) {
			const text = walker.currentNode.textContent.trim();
			const element = walker.currentNode.parentElement;
			if ( ! text || ! element || [ 'SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'OPTION' ].includes( element.tagName ) ) {
				continue;
			}
			if ( element.closest( '.screen-reader-text,[hidden],dialog,#wpadminbar' ) ) {
				continue;
			}
			const box = element.getBoundingClientRect();
			const style = getComputedStyle( element );
			// font-size 0: chữ cố ý giấu đi (nút chỉ có icon), không ai nhìn thấy.
			if ( box.width > 1 && box.height > 1 && 'hidden' !== style.visibility && 'none' !== style.display && parseFloat( style.fontSize ) > 0 ) {
				add( element, parse( style.color ), text );
			}
		}
		if ( ! root ) {
			document.querySelectorAll( 'input[placeholder],textarea[placeholder]' ).forEach( ( element ) => {
				const color = parse( getComputedStyle( element, '::placeholder' ).color );
				if ( element.offsetWidth && color ) {
					add( element, color, element.placeholder );
				}
			} );
		}

		return rows;
	}, selector );

	const collect = async ( label, selector ) => {
		const rows = await scan( selector );
		samples += rows.length;
		rows.filter( ( row ) => row.ratio < row.need ).forEach( ( row ) => {
			const key = [ row.who, row.fg, row.bg ].join( '|' );
			failures[ key ] = failures[ key ] || { ...row, pages: [] };
			if ( ! failures[ key ].pages.includes( label ) ) {
				failures[ key ].pages.push( label );
			}
		} );
	};

	const visit = async ( label, path ) => {
		await page.goto( site + path, { waitUntil: 'load' } );
		await page.evaluate( async () => {
			for ( let y = 0; y < document.body.scrollHeight; y += 600 ) {
				window.scrollTo( 0, y );
				await new Promise( ( done ) => setTimeout( done, 60 ) );
			}
			window.scrollTo( 0, 0 );
			await new Promise( ( done ) => setTimeout( done, 900 ) );
		} );
		await collect( label );
	};

	// Phần tử rê chuột, theo trang. Mỗi mục: [ phần tử cần rê, phần tử cha cần rê trước nếu có ].
	const HOVER = {
		'/': [
			[ '.kio-mega__link' ], [ '.kio-mega__sub a', '.kio-mega__item' ], [ '.kio-action' ], [ '.kio-action--cart' ],
			[ '.kio-header__search button' ], [ '.kio-cats__item' ], [ '.kio-strip__chip[aria-current]' ],
			[ '.kio-strip__chip:not([aria-current])' ], [ '.kio-strip__more' ], [ '.kio-brands__item' ],
			[ '.kio-flash-sale .button:not(.compare)' ], [ '.kio-flash-sale a.compare.button' ],
			[ '.site-footer .kio-footer__menu a' ], [ '.woocommerce-LoopProduct-link' ],
		],
		'/danh-muc-san-pham/dien-thoai/?filtering=1&filter_product_brand=23': [
			[ '.kio-filter-chip.is-active' ], [ '.kio-filter-chip:not(.is-active)' ], [ '.kio-filter-clear' ],
			[ '.kio-sort__item[aria-current]' ], [ '.kio-sort__item:not([aria-current])' ],
			[ '#secondary .widget_layered_nav a' ], [ '.woocommerce-breadcrumb a' ],
		],
		'/san-pham/samsung-galaxy-s26/': [
			[ '.kio-chip.is-selected' ], [ '.kio-chip:not(.is-selected)' ], [ '.single_add_to_cart_button' ],
			[ '.summary a.compare.button' ], [ '.wc-tabs li:not(.active) a' ],
		],
	};

	const hoverPages = async () => {
		for ( const path of Object.keys( HOVER ) ) {
			await page.goto( site + path, { waitUntil: 'load' } );
			await page.waitForTimeout( 500 );
			for ( const [ selector, parent ] of HOVER[ path ] ) {
				const target = page.locator( selector ).first();
				if ( await target.count() ) {
					if ( parent ) {
						await page.locator( parent ).first().hover();
					}
					await target.scrollIntoViewIfNeeded();
					await target.hover( { force: true } );
					await page.waitForTimeout( 250 );
					await collect( 'Rê chuột', selector );
				}
			}
		}
	};

	await visit( 'Trang chủ', '/' );
	await visit( 'Danh mục', '/danh-muc-san-pham/dien-thoai/' );
	await visit( 'Sản phẩm', '/san-pham/samsung-galaxy-s26/' );
	await visit( 'Liên hệ', '/lien-he/' );
	await visit( 'Tài khoản', '/tai-khoan/' );
	await visit( 'Không có kết quả', '/?s=khongcosanphamnay&post_type=product' );

	const id = await page.evaluate( async () => {
		const products = await ( await fetch( '/wp-json/wc/store/v1/products?type=simple&stock_status=instock&per_page=1' ) ).json();
		return products[ 0 ].id;
	} );
	await visit( 'Thông báo thành công', '/?add-to-cart=' + id );
	await visit( 'Giỏ hàng', '/gio-hang/' );
	await visit( 'Thanh toán', '/thanh-toan/' );
	await page.click( '#place_order' );
	await page.waitForTimeout( 2500 );
	await collect( 'Thanh toán có lỗi' );

	await hoverPages();

	return JSON.stringify( { samples, failures: Object.values( failures ).sort( ( a, b ) => a.ratio - b.ratio ) } );
}
