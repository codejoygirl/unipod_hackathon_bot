import { redirect } from "next/navigation";

/** Sign-in is deferred; web chat uses ?k= and ?s= on the home URL. */
export default function LoginPage() {
  redirect("/");
}
