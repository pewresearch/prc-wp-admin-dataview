/**
 * WordPress Dependencies
 */
import { DataForm } from '@wordpress/dataviews/wp';
import { createRoot } from '@wordpress/element';

/**
 * Internal Dependencies
 */
import App from './app';
import './style.scss';

export {
	emitPageExtra,
	HeaderActionsFill,
	PageExtrasFill,
	subscribePageExtra,
} from './slots';

window.prcWpAdminDataviewsWp = { DataForm };
window.prcWpAdminDataviewListStage = App;

document.addEventListener('DOMContentLoaded', () => {
	const container = document.getElementById('prc-wp-admin-dataview');
	if (!container) {
		return;
	}
	if (container.classList.contains('boot-layout-container')) {
		return;
	}
	const root = createRoot(container);
	root.render(<App />);
});
