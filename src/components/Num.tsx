/** Western Arabic numerals, isolated so RTL text never reorders them. */
export function Num({ value, suffix }: { value: number | string; suffix?: string }) {
  return (
    <span className="num">
      {value}
      {suffix ? ` ${suffix}` : ""}
    </span>
  );
}
