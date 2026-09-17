export default function Home() {
  return (
    <main className="mx-auto flex min-h-full w-full max-w-2xl flex-col justify-center gap-4 px-6 py-16">
      <p className="text-sm uppercase tracking-wide text-zinc-500">
        Community Assistant
      </p>
      <h1 className="text-3xl font-semibold tracking-tight text-zinc-900">
        Repository scaffold is ready
      </h1>
      <p className="text-base leading-7 text-zinc-600">
        Product features are not implemented yet. Start with the root README,
        then follow{" "}
        <code className="rounded bg-zinc-100 px-1.5 py-0.5 text-sm">
          docs/prd.md
        </code>{" "}
        and{" "}
        <code className="rounded bg-zinc-100 px-1.5 py-0.5 text-sm">
          docs/implementation-plan.md
        </code>
        .
      </p>
    </main>
  );
}
