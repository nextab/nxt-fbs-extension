(function () {
	'use strict';

	const DESC_MAX       = 300;
	const FALLBACK_CLASS = 'nxt-show-more-fallback';

	function getNaturalDescriptionHeight( content ) {
		const shadowBody = content.shadowRoot?.querySelector( 'body' );
		const prevMax      = content.style.maxHeight;
		const prevOverflow = content.style.overflowY;

		content.style.maxHeight = 'none';
		content.style.overflowY = 'visible';

		let height = content.offsetHeight;
		if ( shadowBody ) {
			height = Math.max(
				height,
				shadowBody.scrollHeight,
				Math.ceil( shadowBody.getBoundingClientRect().height )
			);
		}

		content.style.maxHeight = prevMax;
		content.style.overflowY = prevOverflow;

		return height;
	}

	function isDescriptionOverflowing( content ) {
		if ( content.offsetHeight >= DESC_MAX ) return true;
		if ( content.scrollHeight >= DESC_MAX ) return true;
		return getNaturalDescriptionHeight( content ) > DESC_MAX;
	}

	function hasFluentShowMore( wrap ) {
		return !!wrap.querySelector( '.fbs_show_more_task_description:not(.' + FALLBACK_CLASS + ')' );
	}

	function removeFallback( wrap ) {
		wrap.querySelectorAll( '.' + FALLBACK_CLASS ).forEach( function ( el ) {
			el.remove();
		} );
	}

	function getDescriptionVueProxy( content ) {
		let node = content;
		for ( let i = 0; i < 12 && node; i++ ) {
			const proxy = node.__vueParentComponent?.proxy;
			if (
				proxy &&
				typeof proxy.showToggleDescription === 'function' &&
				'showTaskDescriptionToggle' in proxy
			) {
				return proxy;
			}
			node = node.parentElement;
		}
		return null;
	}

	function forceFluentToggle( content ) {
		const proxy = getDescriptionVueProxy( content );
		if ( !proxy ) return false;

		if ( proxy.isFullScreenMode ) {
			proxy.isFullDescription = true;
			proxy.showTaskDescriptionToggle = false;
			return true;
		}

		if ( isDescriptionOverflowing( content ) ) {
			proxy.showTaskDescriptionToggle = true;
			return true;
		}

		proxy.showToggleDescription();
		return !!proxy.showTaskDescriptionToggle;
	}

	function setExpanded( content, expanded ) {
		content.style.maxHeight = expanded ? 'fit-content' : DESC_MAX + 'px';
		content.style.overflowY = 'hidden';
		const wrap = content.closest( '.fbs-task-description-content-wrap' );
		if ( wrap ) {
			wrap.style.paddingBottom = expanded ? '34px' : '';
		}
	}

	function insertFallbackShowMore( wrap, content ) {
		if ( hasFluentShowMore( wrap ) || wrap.querySelector( '.' + FALLBACK_CLASS ) ) return;

		const btn = document.createElement( 'div' );
		btn.className = 'fbs_show_more_task_description ' + FALLBACK_CLASS;

		const label = document.createElement( 'span' );
		label.textContent = 'Show more';
		btn.appendChild( label );

		btn.addEventListener( 'click', function () {
			const expanded = content.style.maxHeight === 'fit-content';
			setExpanded( content, !expanded );
			label.textContent = expanded ? 'Show more' : 'Show Less';
		} );

		wrap.appendChild( btn );
	}

	function bindShadowMedia( content, onReady ) {
		const root = content.shadowRoot;
		if ( !root ) return;

		root.querySelectorAll( 'img' ).forEach( function ( img ) {
			if ( img.complete ) return;
			img.addEventListener( 'load', onReady, { once: true } );
			img.addEventListener( 'error', onReady, { once: true } );
		} );

		root.querySelectorAll( 'video' ).forEach( function ( video ) {
			if ( video.readyState >= 2 ) return;
			video.addEventListener( 'loadeddata', onReady, { once: true } );
		} );

		root.querySelectorAll( 'link[rel="stylesheet"]' ).forEach( function ( link ) {
			link.addEventListener( 'load', onReady, { once: true } );
			link.addEventListener( 'error', onReady, { once: true } );
		} );
	}

	function ensureDescriptionToggle( content ) {
		const wrap = content.closest( '.fbs-task-description-content-wrap' );
		if ( !wrap ) return;

		if ( hasFluentShowMore( wrap ) ) {
			removeFallback( wrap );
			return;
		}

		if ( !isDescriptionOverflowing( content ) ) return;

		if ( forceFluentToggle( content ) ) {
			setTimeout( function () {
				if ( hasFluentShowMore( wrap ) ) {
					removeFallback( wrap );
					return;
				}
				if ( isDescriptionOverflowing( content ) ) {
					insertFallbackShowMore( wrap, content );
				}
			}, 50 );
			return;
		}

		insertFallbackShowMore( wrap, content );
	}

	function watchDescription( content ) {
		if ( content.dataset.nxtDescWatched === '1' ) {
			ensureDescriptionToggle( content );
			return;
		}
		content.dataset.nxtDescWatched = '1';

		let shadowBound    = false;
		let resizeObserver = null;

		const attachShadowWatchers = function () {
			if ( !content.shadowRoot || shadowBound ) return;
			shadowBound = true;

			bindShadowMedia( content, run );

			if ( typeof MutationObserver !== 'undefined' ) {
				const shadowObserver = new MutationObserver( function () {
					bindShadowMedia( content, run );
					run();
				} );
				shadowObserver.observe( content.shadowRoot, {
					childList: true,
					subtree:   true,
				} );
			}

			const shadowBody = content.shadowRoot.querySelector( 'body' );
			if ( resizeObserver && shadowBody ) {
				resizeObserver.observe( shadowBody );
			}
		};

		const run = function () {
			attachShadowWatchers();
			ensureDescriptionToggle( content );
		};

		run();

		[ 50, 100, 150, 250, 400, 700, 1200, 2000, 3500 ].forEach( function ( ms ) {
			setTimeout( run, ms );
		} );

		if ( typeof ResizeObserver !== 'undefined' ) {
			resizeObserver = new ResizeObserver( run );
			resizeObserver.observe( content );
			attachShadowWatchers();
		}
	}

	function initDescriptionToggles() {
		document.querySelectorAll( '.fbs-task-description-content' ).forEach( watchDescription );
	}

	const observer = new MutationObserver( function ( mutations ) {
		let needDesc = false;

		for ( const mutation of mutations ) {
			if ( mutation.addedNodes.length === 0 ) continue;

			for ( const node of mutation.addedNodes ) {
				if ( !( node instanceof HTMLElement ) ) continue;

				if (
					node.matches?.( '.fbs-task-description-content' ) ||
					node.matches?.( '.fbs-task-description' ) ||
					node.querySelector?.( '.fbs-task-description-content' )
				) {
					needDesc = true;
				}

				if ( node.matches?.( '.fbs_show_more_task_description:not(.' + FALLBACK_CLASS + ')' ) ) {
					const wrap = node.closest( '.fbs-task-description-content-wrap' );
					if ( wrap ) removeFallback( wrap );
				}
			}
		}

		if ( needDesc ) initDescriptionToggles();
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		observer.observe( document.body, { childList: true, subtree: true } );
		initDescriptionToggles();
	} );
} )();
