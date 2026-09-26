type CircularLoaderProps = {
  size?: "xs" | "sm" | "md" | "lg";
  className?: string;
};

const sizeClass = {
  xs: "h-4 w-4 border-[1.5px]",
  sm: "h-5 w-5 border-2",
  md: "h-7 w-7 border-2",
  lg: "h-9 w-9 border-[2.5px]",
} as const;

export function CircularLoader({ size = "md", className = "" }: CircularLoaderProps) {
  return (
    <span
      role="status"
      aria-label="Loading"
      className={`inline-block shrink-0 animate-spin rounded-full border-zinc-200 border-t-zinc-800 ${sizeClass[size]} ${className}`}
    />
  );
}
