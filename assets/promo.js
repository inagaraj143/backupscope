/**
 * BackupScope (free): remembers a dismissed usage notice, so it stays dismissed.
 */
( function () {
	'use strict';
	var cfg = window.BackupScopePromo || {};
	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.classList || ! e.target.classList.contains( 'notice-dismiss' ) ) { return; }
		var notice = e.target.closest( '[data-bsp-notice]' );
		if ( ! notice || ! cfg.ajaxUrl ) { return; }
		var body = new FormData();
		body.append( 'action', 'backupscope_dismiss_notice' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'key', notice.getAttribute( 'data-bsp-notice' ) );
		fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } );
	} );
}() );
