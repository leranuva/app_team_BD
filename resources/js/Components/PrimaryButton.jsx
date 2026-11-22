export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            className={
                'action-primary inline-flex items-center rounded-lg px-4 text-sm font-semibold ' + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
