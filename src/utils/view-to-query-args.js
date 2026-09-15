const ORDERBY_MAP = {
	title: 'title',
	date: 'date',
	modified: 'modified',
	author: 'author',
	id: 'ID',
};

const DATE_DAY_PREFIX = /^(\d{4}-\d{2}-\d{2})/;

// Trash is available in the status filter but is not part of the default query.
export const DEFAULT_LIST_STATUSES = 'publish,draft,pending,private,future';

function firstScalar(value) {
	if (Array.isArray(value)) {
		return value[0];
	}
	return value;
}

function dateTimeValue(value) {
	const raw = firstScalar(value);
	return typeof raw === 'string' ? raw : '';
}

function calendarDay(value) {
	const match = dateTimeValue(value).match(DATE_DAY_PREFIX);
	return match ? match[1] : '';
}

const DATE_OPERATORS = {
	on(args, value) {
		const day = calendarDay(value);
		if (day) {
			args.date_on = day;
		}
	},
	before(args, value) {
		const bound = dateTimeValue(value);
		if (bound) {
			args.before = bound;
		}
	},
	after(args, value) {
		const bound = dateTimeValue(value);
		if (bound) {
			args.after = bound;
		}
	},
};

function applyDateFilter(args, filter) {
	const apply = DATE_OPERATORS[filter.operator];
	if (apply) {
		apply(args, filter.value);
	}
}

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
		if (filter.field === 'date') {
			applyDateFilter(args, filter);
			return;
		}

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
