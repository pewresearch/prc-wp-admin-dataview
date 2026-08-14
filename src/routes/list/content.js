/**
 * Boot route content module. The list UI lives in the classic bundle because
 * WordPress DataViews and most wp packages are not script modules. Boot
 * dynamically imports this file after that bundle has assigned stage.
 */
export function stage() {
	const Stage = window.prcWpAdminDataviewListStage;
	if (!Stage || !window.wp?.element?.createElement) {
		return null;
	}
	return window.wp.element.createElement(Stage);
}
