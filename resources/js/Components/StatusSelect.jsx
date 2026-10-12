import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/react';

export default function StatusSelect({ value, onChange, options, label, className = 'w-full sm:w-52' }) {
    return (
        <Listbox value={value} onChange={onChange}>
            <div className={`relative ${className}`}>
                <ListboxButton
                    className="group flex w-full items-center justify-between rounded-xl bg-slate-100
                        px-4 py-3 text-left text-sm font-semibold transition hover:bg-slate-200
                        data-[open]:bg-slate-200 data-[open]:ring-2 data-[open]:ring-blue-400"
                >
                    <span className="truncate">{label(value)}</span>
                    <svg
                        aria-hidden="true"
                        viewBox="0 0 20 20"
                        fill="none"
                        className="ml-3 h-4 w-4 flex-none text-slate-400 transition-transform duration-200
                            group-data-[open]:rotate-180"
                    >
                        <path
                            d="m5 7.5 5 5 5-5"
                            stroke="currentColor"
                            strokeWidth="1.75"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        />
                    </svg>
                </ListboxButton>
                <ListboxOptions
                    transition
                    className="absolute left-0 right-0 z-30 mt-2 max-h-72 origin-top overflow-auto rounded-xl
                        border border-slate-200 bg-white p-1.5 shadow-xl shadow-slate-900/10
                        transition duration-150 ease-out
                        data-[closed]:-translate-y-1 data-[closed]:scale-95 data-[closed]:opacity-0"
                >
                    {options.map(option => (
                        <ListboxOption
                            key={option || 'all'}
                            value={option}
                            className="flex w-full cursor-pointer items-center rounded-lg px-3 py-2.5
                                text-left text-sm font-semibold capitalize transition
                                data-[selected]:bg-blue-400 data-[selected]:text-slate-950
                                text-slate-600 data-[focus]:bg-slate-100 data-[focus]:text-slate-950"
                        >
                            {({ selected }) => (
                                <>
                                    <span className="mr-2 w-4 text-center">
                                        {selected ? '✓' : ''}
                                    </span>
                                    {label(option)}
                                </>
                            )}
                        </ListboxOption>
                    ))}
                </ListboxOptions>
            </div>
        </Listbox>
    );
}
