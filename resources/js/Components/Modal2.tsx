import Modal from '@/Components/Modal';
import { PropsWithChildren } from 'react';

export default function Dialog({ show, onClose, title, children }: PropsWithChildren<{ show: boolean; onClose: () => void; title: string }>) {
    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <div className="p-6">
                <div className="mb-4 flex items-center justify-between">
                    <h3 className="text-lg font-semibold text-slate-900">{title}</h3>
                    <button onClick={onClose} className="text-slate-400 hover:text-slate-600" aria-label="Close">✕</button>
                </div>
                {children}
            </div>
        </Modal>
    );
}
