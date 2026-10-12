import { createContext, useContext, useEffect, useMemo } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api, setCsrfToken } from '@/api/client';

const AppContext = createContext(null);

const EMPTY = {
    auth: { user: null },
    market: null,
    countries: {},
    translations: {},
};

const logo = (
    <p className="text-2xl font-black tracking-tight">
        <span className="text-blue-500">dao</span>Sport
    </p>
);

export function AppProvider({ children }) {
    const queryClient = useQueryClient();
    const query = useQuery({
        queryKey: ['context'],
        queryFn: () => api.get('/context'),
        staleTime: 30_000,
        retry: 1,
    });

    useEffect(() => {
        if (query.data?.data?.csrf_token) {
            setCsrfToken(query.data.data.csrf_token);
        }
    }, [query.data]);

    const value = useMemo(() => {
        const data = query.data?.data ?? EMPTY;
        return {
            auth: data.auth ?? EMPTY.auth,
            market: data.market ?? EMPTY.market,
            countries: data.countries ?? EMPTY.countries,
            translations: data.translations ?? EMPTY.translations,
            contextLoading: query.isPending,
            refetchContext: () => query.refetch(),
            invalidateAll: () => queryClient.invalidateQueries(),
        };
    }, [query.data, query.isPending, query.refetch, queryClient]);

    let content = children;
    if (query.isPending) {
        content = (
            <div className="flex min-h-screen items-center justify-center bg-[#f7f8fa] text-slate-900">
                {logo}
            </div>
        );
    } else if (query.isError) {
        content = (
            <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-[#f7f8fa]
                px-5 text-center text-slate-900">
                {logo}
                <p className="text-sm text-slate-500">
                    We could not load the store. Please try again.
                </p>
                <button
                    type="button"
                    onClick={() => query.refetch()}
                    className="rounded-full bg-slate-950 px-6 py-3 text-sm font-bold text-white"
                >
                    Retry
                </button>
            </div>
        );
    }

    return <AppContext.Provider value={value}>{content}</AppContext.Provider>;
}

export function useApp() {
    const context = useContext(AppContext);
    if (!context) {
        throw new Error('useApp must be used within AppProvider.');
    }
    return context;
}
