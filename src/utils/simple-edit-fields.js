/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';

export const NO_CHANGE = '__no_change__';

const SKIP_TYPES = new Set([
	'datetime',
	'date',
	'media',
	'color',
	'array',
	'password',
]);

const SKIP_CONTROLS = new Set([
	'datetime',
	'date',
	'media',
	'color',
	'array',
	'password',
	'richtext',
	'toggle',
	'checkbox',
	'radio',
	'toggleGroup',
	'combobox',
]);

const TEXT_TYPES = new Set(['text', 'email', 'url', 'telephone']);
const INTEGER_TYPES = new Set(['integer', 'number']);

function getEditControl(field) {
	const edit = field?.Edit;
	if (typeof edit === 'function') {
		return { kind: 'custom' };
	}
	if (typeof edit === 'string') {
		return { kind: 'named', name: edit };
	}
	if (edit && typeof edit === 'object' && typeof edit.control === 'string') {
		return { kind: 'named', name: edit.control };
	}
	return { kind: 'none' };
}

/**
 * Infer a simple edit control from a DataViews field, or null to skip.
 *
 * @param {Object} field DataViews field.
 * @return {'text'|'textarea'|'integer'|'select'|null} Control name.
 */
export function getSimpleControl(field) {
	if (!field || field.readOnly) {
		return null;
	}

	const edit = getEditControl(field);
	if (edit.kind === 'custom') {
		return null;
	}
	if (edit.kind === 'named' && SKIP_CONTROLS.has(edit.name)) {
		return null;
	}
	if (edit.kind === 'named' && edit.name === 'textarea') {
		return 'textarea';
	}

	const type = field.type;
	if (SKIP_TYPES.has(type)) {
		return null;
	}

	const elements = Array.isArray(field.elements) ? field.elements : [];
	if (elements.length > 0 && type) {
		return 'select';
	}
	if (
		Array.isArray(field.elements) &&
		elements.length === 0 &&
		type !== 'boolean'
	) {
		return null;
	}

	if (edit.kind === 'named' && INTEGER_TYPES.has(edit.name)) {
		return 'integer';
	}
	if (edit.kind === 'named' && TEXT_TYPES.has(edit.name)) {
		return 'text';
	}

	if (!type) {
		return null;
	}

	if (type === 'boolean') {
		return 'select';
	}
	if (INTEGER_TYPES.has(type)) {
		return 'integer';
	}
	if (TEXT_TYPES.has(type)) {
		return 'text';
	}

	return null;
}

function booleanElements() {
	return [
		{ value: 'true', label: __('Yes', 'prc-wp-admin-dataview') },
		{ value: 'false', label: __('No', 'prc-wp-admin-dataview') },
	];
}

/**
 * Fields the list may edit inline or in bulk.
 *
 * @param {Array<Object>} fields                    DataViews fields.
 * @param {Object}        [options]
 * @param {boolean}       [options.canPublish=true] Whether publish/private may be set.
 * @return {Array<{id: string, label: string, control: string, elements: Array}>} Editable fields.
 */
export function getEditableFields(fields, { canPublish = true } = {}) {
	if (!Array.isArray(fields)) {
		return [];
	}

	const editable = [];
	for (const field of fields) {
		const control = getSimpleControl(field);
		if (!control) {
			continue;
		}

		let elements = Array.isArray(field.elements) ? field.elements : [];
		if (
			control === 'select' &&
			elements.length === 0 &&
			field.type === 'boolean'
		) {
			elements = booleanElements();
		}
		if (field.id === 'status') {
			elements = elements.filter((option) => {
				if (
					option.value === 'trash' ||
					option.value === 'auto-draft' ||
					option.value === 'future'
				) {
					return false;
				}
				if (
					!canPublish &&
					(option.value === 'publish' || option.value === 'private')
				) {
					return false;
				}
				return true;
			});
		}
		if (control === 'select' && elements.length === 0) {
			continue;
		}

		editable.push({
			id: field.id,
			label: field.label || field.id,
			control,
			elements,
		});
	}

	return editable;
}

/**
 * DataForm field defs for the bulk modal.
 *
 * @param {Array<Object>} editableFields From getEditableFields.
 * @return {Array<Object>} DataForm fields.
 */
export function toBulkFormFields(editableFields) {
	const noChange = {
		value: NO_CHANGE,
		label: __('No change', 'prc-wp-admin-dataview'),
	};

	return editableFields.map((field) => {
		if (field.control === 'select') {
			const options = field.elements.filter(
				(option) => String(option.value) !== NO_CHANGE
			);
			return {
				id: field.id,
				type: 'text',
				label: field.label,
				elements: [noChange, ...options],
			};
		}
		if (field.control === 'textarea') {
			return {
				id: field.id,
				type: 'text',
				label: field.label,
				Edit: {
					control: 'textarea',
					rows: 4,
				},
			};
		}
		if (field.control === 'integer') {
			return {
				id: field.id,
				type: 'integer',
				label: field.label,
			};
		}
		return {
			id: field.id,
			type: 'text',
			label: field.label,
		};
	});
}

/**
 * Initial bulk draft. Selects start at No change. Text starts empty.
 *
 * @param {Array<Object>} editableFields From getEditableFields.
 * @return {Object} Draft values keyed by field id.
 */
export function createBulkDraft(editableFields) {
	const draft = {};
	for (const field of editableFields) {
		draft[field.id] = field.control === 'select' ? NO_CHANGE : '';
	}
	return draft;
}

/**
 * Fields the user changed in the bulk form.
 *
 * @param {Object}        initial        createBulkDraft() result.
 * @param {Object}        values         Current form values.
 * @param {Array<Object>} editableFields From getEditableFields.
 * @return {Array<{field: string, value: *}>} Updates to apply to each item.
 */
export function collectDirtyUpdates(initial, values, editableFields) {
	const updates = [];
	for (const field of editableFields) {
		const next = values?.[field.id];
		const prev = initial?.[field.id];
		if (Object.is(next, prev)) {
			continue;
		}
		if (
			field.control === 'select' &&
			(next === NO_CHANGE || next === undefined || next === null)
		) {
			continue;
		}
		updates.push({ field: field.id, value: next });
	}
	return updates;
}
