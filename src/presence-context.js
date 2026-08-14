/**
 * WordPress Dependencies
 */
import { createContext, useContext } from '@wordpress/element';

const PresenceEditorsContext = createContext(new Map());

export function PresenceEditorsProvider({ value, children }) {
	return (
		<PresenceEditorsContext.Provider value={value || new Map()}>
			{children}
		</PresenceEditorsContext.Provider>
	);
}

export function usePresenceEditorsContext() {
	return useContext(PresenceEditorsContext);
}
