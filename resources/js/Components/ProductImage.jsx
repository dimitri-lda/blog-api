import { useState } from 'react';
import { useI18n } from '@/lib/i18n';

export default function ProductImage({ src, alt, className = '' }) {
    const [failedSrc, setFailedSrc] = useState(null);
    const t = useI18n();
    if (src && failedSrc !== src) {
        return <img src={src} alt={alt} className={className} onError={() => setFailedSrc(src)} />;
    }
    const fallbackClass = [
        'flex items-center justify-center bg-slate-100 text-sm text-slate-500',
        className,
    ].join(' ');
    return <div role="img" aria-label={alt} className={fallbackClass}>{t('catalog.image_missing')}</div>;
}
