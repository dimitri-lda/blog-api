import { Navigate } from 'react-router-dom';
import { useApp } from '@/app/AppContext';

export default function AdminGuard({ children }) {
    const { auth, contextLoading } = useApp();
    if (contextLoading) {
        return null;
    }
    if (!auth.user) {
        return <Navigate to="/login" replace />;
    }
    if (!auth.user.roles?.includes('ROLE_ADMIN')) {
        return <Navigate to="/" replace />;
    }
    return children;
}
