const ORDERBY_MAP = {
	title: 'title',
	date: 'date',
	modified: 'modified',
	author: 'author',
	id: 'ID',
};

// Trash is available in the status filter but is not part of the default query.
export const DEFAULT_LIST_STATUSES = 'publish,draft,pending,private,future';

export function viewToQueryArgs(view, postType) {
	const args = {
		post_type: postType,
		per_page: view.perPage || 20,
		page: view.page || 1,
		status: DEFAULT_LIST_STATUSES,
	};

	if (view.search) {
		args.search = view.search;
	}

	args.orderby = ORDERBY_MAP[view.sort?.field] || 'date';
	args.order = view.sort?.direction || 'desc';

	view.filters?.forEach((filter) => {
		const values = Array.isArray(filter.value)
			? filter.value
			: [filter.value];
		const joined = values
			.filter((value) => value !== undefined && value !== '')
			.join(',');

		if (!joined) {
			return;
		}

		if (filter.field === 'status') {
			args.status = joined;
		} else if (filter.field === 'author' && filter.operator === 'isNone') {
			args.author_exclude = joined;
		} else {
			args[filter.field] = joined;
		}
	});

	return args;
}
