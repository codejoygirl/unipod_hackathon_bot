import { redirect } from "next/navigation";

/** Legacy /login → home; members sign in with ?phone= on /. */
export default function LoginPage() {
  redirect("/");
}
