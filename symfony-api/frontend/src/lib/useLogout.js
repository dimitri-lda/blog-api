import { useMutation } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { api } from '@/api/client';
import { useApp } from '@/app/AppContext';

export function useLogout() {
    const navigate = useNavigate();
    const { refetchContext } = useApp();

    return useMutation({
        mutationFn: () => api.post('/auth/logout'),
        onSuccess: async () => {
            await refetchContext();
            navigate('/');
        },
    });
}
