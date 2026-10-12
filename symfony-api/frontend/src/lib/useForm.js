import { useCallback, useRef, useState } from 'react';
import { api, RequestError } from '@/api/client';

const flattenErrors = errors =>
    Object.fromEntries(
        Object.entries(errors ?? {}).map(([field, messages]) => [
            field,
            Array.isArray(messages) ? messages[0] : messages,
        ]),
    );

export function useForm(initialValues) {
    const initialRef = useRef(initialValues);
    const [data, setDataState] = useState(initialValues);
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const [recentlySuccessful, setRecentlySuccessful] = useState(false);

    const setData = useCallback((key, value) => {
        setDataState(current => ({ ...current, [key]: value }));
    }, []);

    const reset = useCallback((...fields) => {
        setDataState(current => {
            const next = { ...current };
            const keys = fields.length ? fields : Object.keys(initialRef.current);
            keys.forEach(field => {
                next[field] = initialRef.current[field];
            });
            return next;
        });
    }, []);

    const clearErrors = useCallback(() => setErrors({}), []);

    const submit = useCallback(async (call, options = {}) => {
        setProcessing(true);
        setRecentlySuccessful(false);
        try {
            const response = await call();
            setErrors({});
            setRecentlySuccessful(true);
            options.onSuccess?.(response);
            return response;
        } catch (error) {
            const fieldErrors = error instanceof RequestError
                ? flattenErrors(error.payload?.errors)
                : {};
            setErrors(fieldErrors);
            options.onError?.(fieldErrors);
            return null;
        } finally {
            setProcessing(false);
            options.onFinish?.();
        }
    }, []);

    return {
        data,
        setData,
        errors,
        processing,
        recentlySuccessful,
        reset,
        clearErrors,
        get: (url, options) => submit(() => api.get(url), options),
        post: (url, options) => submit(() => api.post(url, data), options),
        put: (url, options) => submit(() => api.put(url, data), options),
        patch: (url, options) => submit(() => api.patch(url, data), options),
        delete: (url, options) => submit(() => api.delete(url, data), options),
    };
}
