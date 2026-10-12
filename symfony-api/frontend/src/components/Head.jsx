import { useEffect } from 'react';

export default function Head({ title }) {
    useEffect(() => {
        if (title) {
            document.title = title;
        }
    }, [title]);
    return null;
}
